defmodule Chopchop.Links.Click do
  use Ecto.Schema

  schema "clicks" do
    belongs_to :link, Chopchop.Links.Link
    field :clicked_at, :utc_datetime
    field :ip_address, :string
    field :user_agent, :string
    field :referer, :string
  end
end
