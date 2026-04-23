defmodule Chopchop.Links.Link do
  use Ecto.Schema

  schema "links" do
    field :code, :string
    field :url, :string
    field :created_at, :utc_datetime
    field :expires_at, :utc_datetime
    has_many :clicks, Chopchop.Links.Click, foreign_key: :link_id
  end
end
