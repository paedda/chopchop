defmodule ChopchopWeb.LinkController do
  use ChopchopWeb, :controller

  alias Chopchop.{Codegen, Links}

  # GET /health
  def health(conn, _params) do
    json(conn, %{status: "ok", language: "elixir", framework: "phoenix"})
  end

  # POST /chop
  def create(conn, params) do
    url = Map.get(params, "url")
    custom_code = Map.get(params, "custom_code")
    expires_in = Map.get(params, "expires_in")

    with :ok <- validate_url(url),
         {:ok, code} <- resolve_code(custom_code),
         {:ok, expires_at} <- parse_expires_in(expires_in) do
      now = DateTime.utc_now() |> DateTime.truncate(:second)

      case Links.create_link(%{code: code, url: url, created_at: now, expires_at: expires_at}) do
        {:ok, link} ->
          base = "#{conn.scheme}://#{conn.host}#{port_suffix(conn)}"

          conn
          |> put_status(201)
          |> json(%{
            code: link.code,
            short_url: "#{base}/#{link.code}",
            url: link.url,
            created_at: format_datetime(link.created_at),
            expires_at: format_datetime(link.expires_at)
          })

        {:error, _changeset} ->
          conn |> put_status(500) |> json(%{error: "Failed to create link"})
      end
    else
      {:error, :invalid_url} ->
        conn |> put_status(400) |> json(%{error: "Invalid or missing URL"})

      {:error, :invalid_custom_code} ->
        conn
        |> put_status(400)
        |> json(%{error: "custom_code must be 3–20 alphanumeric characters or hyphens"})

      {:error, :code_taken} ->
        conn |> put_status(409) |> json(%{error: "Custom code already taken"})

      {:error, :invalid_expires_in} ->
        conn
        |> put_status(400)
        |> json(%{error: "expires_in must be a positive integer no greater than 2592000"})

      {:error, :too_many_collisions} ->
        conn |> put_status(500) |> json(%{error: "Failed to generate a unique code"})
    end
  end

  # GET /stats/:code
  def stats(conn, %{"code" => code}) do
    case Links.get_stats(code) do
      nil ->
        conn |> put_status(404) |> json(%{error: "Link not found"})

      {link, total, recent} ->
        json(conn, %{
          code: link.code,
          url: link.url,
          created_at: format_datetime(link.created_at),
          expires_at: format_datetime(link.expires_at),
          total_clicks: total,
          recent_clicks:
            Enum.map(recent, fn c ->
              %{
                clicked_at: format_datetime(c.clicked_at),
                referer: c.referer,
                user_agent: c.user_agent
              }
            end)
        })
    end
  end

  # GET /:code
  def resolve(conn, %{"code" => code}) do
    case Links.get_by_code(code) do
      nil ->
        conn |> put_status(404) |> json(%{error: "Link not found"})

      link ->
        if expired?(link) do
          conn |> put_status(410) |> json(%{error: "Link has expired"})
        else
          ip = conn.remote_ip |> ip_to_string()
          user_agent = get_req_header(conn, "user-agent") |> List.first()
          referer = get_req_header(conn, "referer") |> List.first()

          Links.record_click(link, ip, user_agent, referer)

          conn
          |> put_resp_header("location", link.url)
          |> send_resp(301, "")
        end
    end
  end

  # ── helpers ────────────────────────────────────────────────────────────────

  defp validate_url(url) when is_binary(url) do
    case URI.parse(url) do
      %URI{scheme: scheme, host: host} when scheme in ["http", "https"] and is_binary(host) ->
        if String.contains?(host, "."), do: :ok, else: {:error, :invalid_url}

      _ ->
        {:error, :invalid_url}
    end
  end

  defp validate_url(_), do: {:error, :invalid_url}

  defp resolve_code(nil) do
    Codegen.generate()
  end

  defp resolve_code(code) when is_binary(code) do
    if Regex.match?(~r/^[a-zA-Z0-9\-]{3,20}$/, code) do
      if Links.code_taken?(code) do
        {:error, :code_taken}
      else
        {:ok, code}
      end
    else
      {:error, :invalid_custom_code}
    end
  end

  defp resolve_code(_), do: {:error, :invalid_custom_code}

  defp parse_expires_in(nil), do: {:ok, nil}

  defp parse_expires_in(seconds) when is_integer(seconds) and seconds > 0 and seconds <= 2_592_000 do
    {:ok, DateTime.utc_now() |> DateTime.add(seconds, :second) |> DateTime.truncate(:second)}
  end

  defp parse_expires_in(_), do: {:error, :invalid_expires_in}

  defp expired?(%{expires_at: nil}), do: false

  defp expired?(%{expires_at: expires_at}) do
    DateTime.compare(DateTime.utc_now(), expires_at) == :gt
  end

  defp format_datetime(nil), do: nil

  defp format_datetime(%DateTime{} = dt) do
    dt |> DateTime.truncate(:second) |> DateTime.to_iso8601() |> String.replace("Z", "+00:00")
  end

  defp ip_to_string({a, b, c, d}), do: "#{a}.#{b}.#{c}.#{d}"

  defp ip_to_string({a, b, c, d, e, f, g, h}) do
    [a, b, c, d, e, f, g, h]
    |> Enum.map(&Integer.to_string(&1, 16))
    |> Enum.join(":")
  end

  defp port_suffix(%Plug.Conn{scheme: :http, port: 80}), do: ""
  defp port_suffix(%Plug.Conn{scheme: :https, port: 443}), do: ""
  defp port_suffix(%Plug.Conn{port: port}), do: ":#{port}"
end
