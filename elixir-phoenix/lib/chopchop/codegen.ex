defmodule Chopchop.Codegen do
  @alphabet ~c"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
  @length 6
  @max_attempts 3

  alias Chopchop.Links.Link
  alias Chopchop.Repo

  def generate, do: generate(@max_attempts)

  defp generate(0), do: {:error, :too_many_collisions}

  defp generate(attempts) do
    code = random_code()

    case Repo.get_by(Link, code: code) do
      nil -> {:ok, code}
      _ -> generate(attempts - 1)
    end
  end

  defp random_code do
    :crypto.strong_rand_bytes(@length)
    |> :binary.bin_to_list()
    |> Enum.map(fn byte -> Enum.at(@alphabet, rem(byte, length(@alphabet))) end)
    |> List.to_string()
  end
end
