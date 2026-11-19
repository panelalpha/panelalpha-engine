// Command seed creates RUSEON Core's first admin from the login the engine
// generates (RUSEON_ADMIN_USERNAME / RUSEON_ADMIN_PASSWORD), through the
// app's own storage package and user model, before the server first opens
// the database. Only while the database has no user at all: the server then
// finds one and generates no password of its own, and a password changed in
// RUSEON Core is never touched. It always exits 0; a failure leaves the
// server to generate its admin as before, and says so.
package main

import (
	"fmt"
	"os"

	"golang.org/x/crypto/bcrypt"

	"github.com/RUSEGAL/ruseon-core/v2/internal/models"
	"github.com/RUSEGAL/ruseon-core/v2/pkg/storage"
)

func say(format string, args ...any) {
	fmt.Printf("[panelalpha] ruseon-core: "+format+"\n", args...)
}

func main() {
	name := os.Getenv("RUSEON_ADMIN_USERNAME")
	if name == "" {
		name = "admin"
	}
	password := os.Getenv("RUSEON_ADMIN_PASSWORD")
	if password == "" {
		say("no RUSEON_ADMIN_PASSWORD from the engine; the server generates its admin")
		return
	}

	// The server's own path: storage.NewStorage("data") from /app.
	store, err := storage.NewStorage("data")
	if err != nil {
		say("could not open the database (%v); the server generates its admin", err)
		return
	}
	defer store.Close()

	has, err := store.HasUsers()
	if err != nil {
		say("could not read the users (%v); the server generates its admin", err)
		return
	}
	if has {
		return
	}

	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err == nil {
		err = store.SaveUser(&models.User{Username: name, PasswordHash: string(hash), Role: models.RoleAdmin})
	}
	if err != nil {
		say("could not create the admin (%v); the server generates its admin", err)
		return
	}
	say("created the admin %s with the login the engine generated", name)
}
