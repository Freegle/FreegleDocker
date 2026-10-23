package charity

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestCreateCharity_Validation(t *testing.T) {
	app := fiber.New()
	app.Post("/charity", CreateCharity)

	tests := []struct {
		name        string
		body        string
		contentType string
		status      int
		msg         string
	}{
		{"bad json", `{nope`, "application/json", 400, "Invalid request body"},
		{"empty body", ``, "application/json", 400, ""},
		{"bad email only name", `{"orgname":"X","contactemail":""}`, "application/json", 400, "Contact email is required"},
		{"missing org name", `{"contactemail":"a@b.com"}`, "application/json", 400, "Organisation name is required"},
		{"missing email", `{"orgname":"Org"}`, "application/json", 400, "Contact email is required"},
		{"empty strings", `{"orgname":"","contactemail":""}`, "application/json", 400, "Organisation name is required"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("POST", "/charity", strings.NewReader(tc.body))
			req.Header.Set("Content-Type", tc.contentType)
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, tc.status, resp.StatusCode)
		})
	}
}

func TestCharity_TableName(t *testing.T) {
	assert.Equal(t, "charities", Charity{}.TableName())
}
