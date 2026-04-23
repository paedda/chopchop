using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;
using Npgsql;

var codeRegex = new Regex(@"^[a-zA-Z0-9\-]{3,20}$", RegexOptions.Compiled);

// ── database ──────────────────────────────────────────────────────────────────

var databaseUrl = Environment.GetEnvironmentVariable("DATABASE_URL")
    ?? throw new InvalidOperationException("DATABASE_URL is required");

var dataSource = new NpgsqlDataSourceBuilder(ParseConnectionString(databaseUrl)).Build();

// ── app ───────────────────────────────────────────────────────────────────────

var builder = WebApplication.CreateBuilder(args);
builder.WebHost.UseUrls("http://0.0.0.0:8000");
builder.Services.AddSingleton(dataSource);

var app = builder.Build();

// ── routes ────────────────────────────────────────────────────────────────────

// GET /health
app.MapGet("/health", () => Results.Ok(new
{
    status    = "ok",
    language  = "csharp",
    framework = "asp.net core"
}));

// POST /chop
app.MapPost("/chop", async (HttpContext ctx, NpgsqlDataSource db) =>
{
    JsonElement body;
    try { body = await JsonSerializer.DeserializeAsync<JsonElement>(ctx.Request.Body); }
    catch { return Error(400, "Invalid or missing URL"); }

    var url = body.TryGetProperty("url", out var u) && u.ValueKind == JsonValueKind.String
        ? u.GetString() : null;

    if (!IsValidUrl(url))
        return Error(400, "Invalid or missing URL");

    string? customCode = body.TryGetProperty("custom_code", out var cc) && cc.ValueKind == JsonValueKind.String
        ? cc.GetString() : null;

    int? expiresIn = body.TryGetProperty("expires_in", out var ei) && ei.ValueKind == JsonValueKind.Number
        ? ei.GetInt32() : null;

    string code;
    if (customCode is not null)
    {
        if (!codeRegex.IsMatch(customCode))
            return Error(400, "custom_code must be 3–20 alphanumeric characters or hyphens");

        await using var checkCmd = db.CreateCommand("SELECT EXISTS(SELECT 1 FROM links WHERE code = $1)");
        checkCmd.Parameters.AddWithValue(customCode);
        if ((bool)(await checkCmd.ExecuteScalarAsync())!)
            return Error(409, "Custom code already taken");

        code = customCode;
    }
    else
    {
        var generated = await GenerateCode(db);
        if (generated is null) return Error(500, "Failed to generate a unique code");
        code = generated;
    }

    DateTime? expiresAt = null;
    if (expiresIn is not null)
    {
        if (expiresIn <= 0 || expiresIn > 2_592_000)
            return Error(400, "expires_in must be a positive integer no greater than 2592000");
        expiresAt = DateTime.UtcNow.AddSeconds(expiresIn.Value);
    }

    await using var cmd = db.CreateCommand(
        "INSERT INTO links (code, url, created_at, expires_at) VALUES ($1,$2,NOW(),$3) RETURNING created_at, expires_at");
    cmd.Parameters.AddWithValue(code);
    cmd.Parameters.AddWithValue(url!);
    cmd.Parameters.AddWithValue(expiresAt.HasValue ? (object)expiresAt.Value : DBNull.Value);

    await using var reader = await cmd.ExecuteReaderAsync();
    await reader.ReadAsync();
    var createdAt = reader.GetDateTime(0);
    var dbExpiresAt = reader.IsDBNull(1) ? (DateTime?)null : reader.GetDateTime(1);

    var host = $"{ctx.Request.Scheme}://{ctx.Request.Host}";
    return Results.Json(new
    {
        code,
        short_url  = $"{host}/{code}",
        url,
        created_at = Fmt(createdAt),
        expires_at = FmtNullable(dbExpiresAt)
    }, statusCode: 201);
});

// GET /stats/{code}
app.MapGet("/stats/{code}", async (string code, NpgsqlDataSource db) =>
{
    await using var linkCmd = db.CreateCommand("SELECT id, url, created_at, expires_at FROM links WHERE code = $1");
    linkCmd.Parameters.AddWithValue(code);
    await using var linkReader = await linkCmd.ExecuteReaderAsync();
    if (!await linkReader.ReadAsync()) return Error(404, "Link not found");

    var id        = linkReader.GetInt64(0);
    var url       = linkReader.GetString(1);
    var createdAt = linkReader.GetDateTime(2);
    var expiresAt = linkReader.IsDBNull(3) ? (DateTime?)null : linkReader.GetDateTime(3);
    await linkReader.CloseAsync();

    await using var countCmd = db.CreateCommand("SELECT COUNT(*) FROM clicks WHERE link_id = $1");
    countCmd.Parameters.AddWithValue(id);
    var total = (long)(await countCmd.ExecuteScalarAsync())!;

    await using var clicksCmd = db.CreateCommand(
        "SELECT clicked_at, referer, user_agent FROM clicks WHERE link_id = $1 ORDER BY clicked_at DESC LIMIT 10");
    clicksCmd.Parameters.AddWithValue(id);
    await using var clicksReader = await clicksCmd.ExecuteReaderAsync();

    var recent = new List<object>();
    while (await clicksReader.ReadAsync())
    {
        recent.Add(new
        {
            clicked_at = Fmt(clicksReader.GetDateTime(0)),
            referer    = clicksReader.IsDBNull(1) ? null : clicksReader.GetString(1),
            user_agent = clicksReader.IsDBNull(2) ? null : clicksReader.GetString(2)
        });
    }

    return Results.Ok(new
    {
        code,
        url,
        created_at    = Fmt(createdAt),
        expires_at    = FmtNullable(expiresAt),
        total_clicks  = total,
        recent_clicks = recent
    });
});

// GET /{code}
app.MapGet("/{code}", async (string code, HttpContext ctx, NpgsqlDataSource db) =>
{
    await using var cmd = db.CreateCommand("SELECT id, url, expires_at FROM links WHERE code = $1");
    cmd.Parameters.AddWithValue(code);
    await using var reader = await cmd.ExecuteReaderAsync();
    if (!await reader.ReadAsync()) return Error(404, "Link not found");

    var id        = reader.GetInt64(0);
    var url       = reader.GetString(1);
    var expiresAt = reader.IsDBNull(2) ? (DateTime?)null : reader.GetDateTime(2);
    await reader.CloseAsync();

    if (expiresAt.HasValue && expiresAt.Value < DateTime.UtcNow)
        return Error(410, "Link has expired");

    var ip        = ctx.Connection.RemoteIpAddress?.ToString();
    var forwarded = ctx.Request.Headers["X-Forwarded-For"].FirstOrDefault();
    if (!string.IsNullOrWhiteSpace(forwarded))
        ip = forwarded.Split(',')[0].Trim();

    var userAgent = ctx.Request.Headers.UserAgent.ToString();
    var referer   = ctx.Request.Headers.Referer.ToString();

    await using var clickCmd = db.CreateCommand(
        "INSERT INTO clicks (link_id, clicked_at, ip_address, user_agent, referer) VALUES ($1,NOW(),$2,$3,$4)");
    clickCmd.Parameters.AddWithValue(id);
    clickCmd.Parameters.AddWithValue(string.IsNullOrEmpty(ip)        ? DBNull.Value : (object)ip);
    clickCmd.Parameters.AddWithValue(string.IsNullOrEmpty(userAgent) ? DBNull.Value : (object)userAgent);
    clickCmd.Parameters.AddWithValue(string.IsNullOrEmpty(referer)   ? DBNull.Value : (object)referer);
    await clickCmd.ExecuteNonQueryAsync();

    return Results.Redirect(url, permanent: true);
});

app.Run();

// ── helpers ───────────────────────────────────────────────────────────────────

static IResult Error(int status, string message) =>
    Results.Json(new { error = message }, statusCode: status);

static string Fmt(DateTime dt) =>
    dt.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss+00:00");

static string? FmtNullable(DateTime? dt) =>
    dt.HasValue ? Fmt(dt.Value) : null;

static bool IsValidUrl(string? url)
{
    if (url is null) return false;
    if (!url.StartsWith("http://", StringComparison.OrdinalIgnoreCase) &&
        !url.StartsWith("https://", StringComparison.OrdinalIgnoreCase)) return false;
    return Uri.TryCreate(url, UriKind.Absolute, out var uri) && uri.Host.Contains('.');
}

static async Task<string?> GenerateCode(NpgsqlDataSource db)
{
    const string alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    for (var i = 0; i < 3; i++)
    {
        var code = new string(Enumerable.Range(0, 6)
            .Select(_ => alphabet[RandomNumberGenerator.GetInt32(alphabet.Length)])
            .ToArray());

        await using var cmd = db.CreateCommand("SELECT EXISTS(SELECT 1 FROM links WHERE code = $1)");
        cmd.Parameters.AddWithValue(code);
        if (!(bool)(await cmd.ExecuteScalarAsync())!)
            return code;
    }
    return null;
}

static string ParseConnectionString(string url)
{
    var uri      = new Uri(url);
    var userInfo = uri.UserInfo.Split(':', 2);
    return new NpgsqlConnectionStringBuilder
    {
        Host     = uri.Host,
        Port     = uri.Port > 0 ? uri.Port : 5432,
        Database = uri.AbsolutePath.TrimStart('/'),
        Username = userInfo[0],
        Password = userInfo.Length > 1 ? userInfo[1] : string.Empty
    }.ConnectionString;
}

