<?php

namespace App\Service;

use App\Repository\LinkRepository;

/**
 * Generates unique base62 short codes for new links.
 *
 * Codes are LENGTH characters drawn from a 62-character alphabet (a-z, A-Z, 0-9),
 * giving 62^6 ≈ 56 billion possible values. On a collision the generator retries
 * up to MAX_RETRIES times before throwing.
 */
class CodeGenerator
{
    private const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const LENGTH = 6;
    private const MAX_RETRIES = 3;

    /**
     * Generates a unique short code that does not already exist in the database.
     *
     * Each attempt draws a fresh random code and checks it against the repository.
     * The check-then-insert is not atomic, but the `code` column has a UNIQUE
     * constraint so any race-condition duplicate will surface as a DB exception
     * rather than silent data corruption.
     *
     * @param LinkRepository $repository Used to detect collisions before insert
     *
     * @return string A unique LENGTH-character base62 code
     *
     * @throws \RuntimeException If every retry collides (astronomically unlikely in practice)
     */
    public function generate(LinkRepository $repository): string
    {
        for ($i = 0; $i < self::MAX_RETRIES; $i++) {
            $code = $this->randomCode();
            if ($repository->findOneBy(['code' => $code]) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique code after ' . self::MAX_RETRIES . ' attempts');
    }

    /**
     * Returns a random LENGTH-character string drawn from CHARSET.
     *
     * Uses random_int() which is backed by the OS CSPRNG, making the output
     * unpredictable and safe for use as a public-facing identifier.
     *
     * @return string Random base62 string of exactly LENGTH characters
     */
    private function randomCode(): string
    {
        $code = '';
        $max = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::CHARSET[random_int(0, $max)];
        }

        return $code;
    }
}
