import Config

config :chopchop, ChopchopWeb.Endpoint,
  adapter: Bandit.PhoenixAdapter,
  render_errors: [formats: [json: ChopchopWeb.ErrorJSON], layout: false],
  pubsub_server: Chopchop.PubSub

config :logger, :console,
  format: "$time $metadata[$level] $message\n",
  metadata: [:request_id]

config :phoenix, :json_library, Jason
