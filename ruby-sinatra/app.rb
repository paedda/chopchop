require 'sinatra'
require 'pg'
require 'json'
require 'time'
require 'uri'
require 'securerandom'
require 'connection_pool'

# ── configuration ─────────────────────────────────────────────────────────────

configure do
  set :show_exceptions, false
  set :raise_errors,    false
end

# ── database ──────────────────────────────────────────────────────────────────

POOL = ConnectionPool.new(size: 10, timeout: 5) do
  PG.connect(ENV.fetch('DATABASE_URL'))
end

# ── request body ──────────────────────────────────────────────────────────────

before do
  content_type 'application/json'
  if request.content_type&.include?('application/json')
    request.body.rewind
    @body = JSON.parse(request.body.read)
  else
    @body = {}
  end
rescue JSON::ParserError
  @body = {}
end

# ── helpers ───────────────────────────────────────────────────────────────────

ALPHABET   = (('a'..'z').to_a + ('A'..'Z').to_a + ('0'..'9').to_a).freeze
CODE_RE    = /\A[a-zA-Z0-9\-]{3,20}\z/
MAX_EXPIRY = 2_592_000

def valid_url?(url)
  return false unless url.is_a?(String)
  uri = URI.parse(url)
  return false unless %w[http https].include?(uri.scheme)
  uri.host&.include?('.') || false
rescue URI::InvalidURIError
  false
end

def generate_code(db)
  3.times do
    code = Array.new(6) { ALPHABET[SecureRandom.random_number(ALPHABET.size)] }.join
    return code if db.exec_params('SELECT 1 FROM links WHERE code = $1', [code]).ntuples.zero?
  end
  halt 500, { error: 'Failed to generate a unique code' }.to_json
end

def fmt(ts)
  return nil if ts.nil?
  Time.parse(ts.to_s).utc.strftime('%Y-%m-%dT%H:%M:%S+00:00')
end

def json_error(status, message)
  halt status, { error: message }.to_json
end

# ── routes ────────────────────────────────────────────────────────────────────

# GET /health  — no DB needed
get '/health' do
  { status: 'ok', language: 'ruby', framework: 'sinatra' }.to_json
end

# POST /chop
post '/chop' do
  url        = @body['url']
  custom     = @body['custom_code']
  expires_in = @body['expires_in']

  json_error 400, 'Invalid or missing URL' unless valid_url?(url)

  POOL.with do |db|
    code = if custom
      json_error 400, 'custom_code must be 3–20 alphanumeric characters or hyphens' unless custom.match?(CODE_RE)
      json_error 409, 'Custom code already taken' if db.exec_params('SELECT 1 FROM links WHERE code = $1', [custom]).ntuples > 0
      custom
    else
      generate_code(db)
    end

    expires_at = if expires_in
      unless expires_in.is_a?(Integer) && expires_in > 0 && expires_in <= MAX_EXPIRY
        json_error 400, 'expires_in must be a positive integer no greater than 2592000'
      end
      (Time.now.utc + expires_in).iso8601
    end

    row = db.exec_params(
      'INSERT INTO links (code, url, created_at, expires_at) VALUES ($1,$2,NOW(),$3) RETURNING *',
      [code, url, expires_at]
    ).first

    base = "#{request.scheme}://#{request.host_with_port}"
    status 201
    {
      code:       row['code'],
      short_url:  "#{base}/#{row['code']}",
      url:        row['url'],
      created_at: fmt(row['created_at']),
      expires_at: fmt(row['expires_at'])
    }.to_json
  end
end

# GET /stats/:code  (must be before /:code)
get '/stats/:code' do
  POOL.with do |db|
    row = db.exec_params('SELECT * FROM links WHERE code = $1', [params[:code]]).first
    json_error 404, 'Link not found' unless row

    total = db.exec_params('SELECT COUNT(*) FROM clicks WHERE link_id = $1', [row['id']]).first['count'].to_i
    recent = db.exec_params(
      'SELECT clicked_at, referer, user_agent FROM clicks WHERE link_id = $1 ORDER BY clicked_at DESC LIMIT 10',
      [row['id']]
    ).map { |c| { clicked_at: fmt(c['clicked_at']), referer: c['referer'], user_agent: c['user_agent'] } }

    {
      code:          row['code'],
      url:           row['url'],
      created_at:    fmt(row['created_at']),
      expires_at:    fmt(row['expires_at']),
      total_clicks:  total,
      recent_clicks: recent
    }.to_json
  end
end

# GET /:code
get '/:code' do
  POOL.with do |db|
    row = db.exec_params('SELECT * FROM links WHERE code = $1', [params[:code]]).first
    json_error 404, 'Link not found' unless row

    if row['expires_at'] && Time.parse(row['expires_at'].to_s).utc < Time.now.utc
      json_error 410, 'Link has expired'
    end

    ip         = request.env['HTTP_X_FORWARDED_FOR']&.split(',')&.first&.strip || request.ip
    user_agent = request.user_agent
    referer    = request.referer

    db.exec_params(
      'INSERT INTO clicks (link_id, clicked_at, ip_address, user_agent, referer) VALUES ($1,NOW(),$2,$3,$4)',
      [row['id'], ip, user_agent, referer]
    )

    redirect row['url'], 301
  end
end

# ── error handlers ────────────────────────────────────────────────────────────

not_found { { error: 'Not found' }.to_json }
error     { { error: 'Internal server error' }.to_json }
