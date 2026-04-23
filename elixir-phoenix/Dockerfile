FROM elixir:1.16

WORKDIR /app

RUN mix local.hex --force && mix local.rebar --force

ENV MIX_ENV=prod

# Fetch and compile dependencies first (cached layer)
COPY mix.exs ./
RUN mix deps.get --only prod && mix deps.compile

# Copy config and source, then compile the app
COPY config config
COPY lib lib
RUN mix compile

EXPOSE 8000
CMD ["mix", "phx.server"]
