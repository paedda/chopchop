import Config

database_url =
  System.get_env("DATABASE_URL") ||
    raise "environment variable DATABASE_URL is missing"

config :chopchop, Chopchop.Repo,
  url: database_url,
  pool_size: 10,
  ssl: false

config :chopchop, ChopchopWeb.Endpoint,
  http: [ip: {0, 0, 0, 0}, port: 8000],
  secret_key_base:
    System.get_env("SECRET_KEY_BASE") ||
      Base.encode64(:crypto.strong_rand_bytes(48))
