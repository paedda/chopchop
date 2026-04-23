defmodule ChopchopWeb.Endpoint do
  use Phoenix.Endpoint, otp_app: :chopchop

  plug Plug.RequestId

  plug Plug.Parsers,
    parsers: [:urlencoded, :json],
    pass: ["*/*"],
    json_decoder: Phoenix.json_library()

  plug Plug.MethodOverride
  plug Plug.Head
  plug ChopchopWeb.Router
end
