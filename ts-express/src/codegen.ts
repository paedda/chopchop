import { Pool } from "pg";

const ALPHABET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
const CODE_LENGTH = 6;
const MAX_ATTEMPTS = 3;

function randomCode(): string {
  let code = "";
  const bytes = new Uint8Array(CODE_LENGTH);
  crypto.getRandomValues(bytes);
  for (const byte of bytes) {
    code += ALPHABET[byte % ALPHABET.length];
  }
  return code;
}

export async function generateCode(pool: Pool): Promise<string> {
  for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
    const code = randomCode();
    const result = await pool.query("SELECT 1 FROM links WHERE code = $1", [code]);
    if (result.rowCount === 0) {
      return code;
    }
  }
  throw new Error("Failed to generate a unique code after multiple attempts");
}
