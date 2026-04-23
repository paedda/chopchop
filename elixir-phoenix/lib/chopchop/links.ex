defmodule Chopchop.Links do
  import Ecto.Query

  alias Chopchop.Repo
  alias Chopchop.Links.{Click, Link}

  def get_by_code(code) do
    Repo.get_by(Link, code: code)
  end

  def create_link(attrs) do
    %Link{}
    |> Ecto.Changeset.cast(attrs, [:code, :url, :created_at, :expires_at])
    |> Ecto.Changeset.validate_required([:code, :url, :created_at])
    |> Repo.insert()
  end

  def code_taken?(code) do
    Repo.exists?(from l in Link, where: l.code == ^code)
  end

  def get_stats(code) do
    case Repo.get_by(Link, code: code) do
      nil ->
        nil

      link ->
        total =
          Repo.aggregate(from(c in Click, where: c.link_id == ^link.id), :count)

        recent =
          Repo.all(
            from c in Click,
              where: c.link_id == ^link.id,
              order_by: [desc: c.clicked_at],
              limit: 10
          )

        {link, total, recent}
    end
  end

  def record_click(link, ip_address, user_agent, referer) do
    %Click{
      link_id: link.id,
      clicked_at: DateTime.utc_now() |> DateTime.truncate(:second),
      ip_address: ip_address,
      user_agent: user_agent,
      referer: referer
    }
    |> Repo.insert()
  end
end
