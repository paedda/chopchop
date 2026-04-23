defmodule Chopchop.Repo do
  use Ecto.Repo,
    otp_app: :chopchop,
    adapter: Ecto.Adapters.Postgres
end
