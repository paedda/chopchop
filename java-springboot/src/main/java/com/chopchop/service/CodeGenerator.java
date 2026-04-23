package com.chopchop.service;

import com.chopchop.repository.LinkRepository;
import org.springframework.stereotype.Service;

import java.security.SecureRandom;

@Service
public class CodeGenerator {

    private static final String ALPHABET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    private static final int CODE_LENGTH = 6;
    private static final int MAX_ATTEMPTS = 3;

    private final SecureRandom random = new SecureRandom();
    private final LinkRepository linkRepository;

    public CodeGenerator(LinkRepository linkRepository) {
        this.linkRepository = linkRepository;
    }

    public String generate() {
        for (int i = 0; i < MAX_ATTEMPTS; i++) {
            String code = randomCode();
            if (!linkRepository.existsByCode(code)) {
                return code;
            }
        }
        throw new IllegalStateException("Failed to generate a unique code after " + MAX_ATTEMPTS + " attempts");
    }

    private String randomCode() {
        StringBuilder sb = new StringBuilder(CODE_LENGTH);
        byte[] bytes = new byte[CODE_LENGTH];
        random.nextBytes(bytes);
        for (byte b : bytes) {
            sb.append(ALPHABET.charAt(Math.abs(b % ALPHABET.length())));
        }
        return sb.toString();
    }
}
