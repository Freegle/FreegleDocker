package embedding

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestEmbedQuerySuccess(t *testing.T) {
	// Create a mock sidecar that returns a valid embedding
	vec := make([]float32, EmbeddingDim)
	for i := range vec {
		vec[i] = float32(i) * 0.001
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/embed", r.URL.Path)
		assert.Equal(t, "POST", r.Method)
		assert.Equal(t, "application/json", r.Header.Get("Content-Type"))

		var req embedRequest
		err := json.NewDecoder(r.Body).Decode(&req)
		require.NoError(t, err)
		assert.Equal(t, []string{"sofa"}, req.Texts)

		resp := embedResponse{Embeddings: [][]float32{vec}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	result, err := EmbedQuery("sofa")
	require.NoError(t, err)
	assert.Len(t, result, EmbeddingDim)
	assert.InDelta(t, 0.001, result[1], 1e-6)
}

func TestEmbedQueryServerError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(500)
		w.Write([]byte(`{"error":"model not loaded"}`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar returned 500")
}

func TestEmbedQueryEmptyEmbeddings(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := embedResponse{Embeddings: [][]float32{}}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "no embeddings returned")
}

func TestEmbedQueryConnectionError(t *testing.T) {
	// Start and immediately close to get a guaranteed-refused port
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	origURL := sidecarURL
	sidecarURL = url
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "sidecar request")
}

func TestEmbedQueryBadJSON(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`not json`))
	}))
	defer server.Close()

	origURL := sidecarURL
	sidecarURL = server.URL
	defer func() { sidecarURL = origURL }()

	_, err := EmbedQuery("sofa")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "unmarshal")
}
