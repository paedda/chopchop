package main

import (
	"context"
	"crypto/rand"
	"errors"
	"math/big"

	"github.com/jackc/pgx/v5/pgxpool"
)

const alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
const codeLength = 6
const maxAttempts = 3

func generateCode(ctx context.Context, db *pgxpool.Pool) (string, error) {
	for range maxAttempts {
		code, err := randomCode()
		if err != nil {
			return "", err
		}
		var exists bool
		if err := db.QueryRow(ctx, "SELECT EXISTS(SELECT 1 FROM links WHERE code = $1)", code).Scan(&exists); err != nil {
			return "", err
		}
		if !exists {
			return code, nil
		}
	}
	return "", errors.New("failed to generate unique code after max attempts")
}

func randomCode() (string, error) {
	b := make([]byte, codeLength)
	for i := range b {
		n, err := rand.Int(rand.Reader, big.NewInt(int64(len(alphabet))))
		if err != nil {
			return "", err
		}
		b[i] = alphabet[n.Int64()]
	}
	return string(b), nil
}
