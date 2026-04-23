defmodule ChopchopWeb.Router do
  use ChopchopWeb, :router

  pipeline :api do
    # No Accept-header check: redirect endpoint sends non-JSON responses
  end

  scope "/", ChopchopWeb do
    pipe_through :api

    get "/health", LinkController, :health
    post "/chop", LinkController, :create
    get "/stats/:code", LinkController, :stats
    # Must be last — matches any single-segment path
    get "/:code", LinkController, :resolve
  end
end
