package embedding

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

var sidecarURL string

func init() {
	sidecarURL = os.Getenv("EMBEDDING_SIDECAR_URL")
	if sidecarURL == "" {
		sidecarURL = "http://embedding-sidecar:3200"
	}
}

type embedRequest struct {
	Texts []string `json:"texts"`
}

type embedResponse struct {
	Embeddings [][]float32 `json:"embeddings"`
	Error      string      `json:"error,omitempty"`
}

var client = &http.Client{Timeout: 10 * time.Second}

// SetSidecarURL overrides the sidecar URL (for testing).
func SetSidecarURL(url string) {
	if url == "" {
		url = "http://embedding-sidecar:3200"
	}
	sidecarURL = url
}

// EmbedQuery calls the sidecar to embed a single search query.
// Returns a normalized float32 slice of length EmbeddingDim.
func EmbedQuery(text string) ([]float32, error) {
	body, err := json.Marshal(embedRequest{Texts: []string{text}})
	if err != nil {
		return nil, fmt.Errorf("marshal: %w", err)
	}

	resp, err := client.Post(sidecarURL+"/embed", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("sidecar request: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}

	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("sidecar returned %d: %s", resp.StatusCode, string(respBody))
	}

	var result embedResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, fmt.Errorf("unmarshal: %w", err)
	}

	if len(result.Embeddings) == 0 {
		return nil, fmt.Errorf("no embeddings returned")
	}

	return result.Embeddings[0], nil
}
