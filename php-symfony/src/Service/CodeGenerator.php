<?php

namespace App\Service;

use App\Repository\LinkRepository;

class CodeGenerator
{
    private const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const LENGTH = 6;
    private const MAX_RETRIES = 3;

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
