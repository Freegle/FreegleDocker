package test

import (
	"encoding/json"
	"math"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func makeTestVec(seed float32) [embedding.EmbeddingDim]float32 {
	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = seed + float32(i)*0.01
		norm += v[i] * v[i]
	}
	norm = float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= norm
	}
	return v
}

func mockSidecarReturning(t *testing.T, vec []float32) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{vec}})
	}))
}

func TestVectorSearchBasic(t *testing.T) {
	sofaVec := makeTestVec(0.5)
	chairVec := makeTestVec(0.51)
	bikeVec := makeTestVec(5.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa bed", Arrival: time.Now(), Vec: sofaVec},
		{Msgid: 2, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Chair", Arrival: time.Now(), Vec: chairVec},
		{Msgid: 3, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Bike", Arrival: time.Now(), Vec: bikeVec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, sofaVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.NotEmpty(t, results)
	assert.Equal(t, uint64(1), results[0].Msgid)
	assert.Equal(t, "Vector", results[0].Matchedon.Type)
	assert.Equal(t, "sofa", results[0].Matchedon.Word)
}

func TestVectorSearchKeywordBoost(t *testing.T) {
	vec := makeTestVec(1.0)
	vecSimilar := makeTestVec(1.001)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 10, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Table lamp", Vec: vecSimilar},
		{Msgid: 11, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Sofa bed", Vec: vecSimilar},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 2)
	// Sofa should be boosted to first by keyword match in subject
	assert.Equal(t, uint64(11), results[0].Msgid)
}

func TestVectorSearchWithMsgtypeFilter(t *testing.T) {
	vec := makeTestVec(1.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 20, Groupid: 100, Msgtype: "Offer", Lat: 51.5, Lng: -0.1, Subject: "OFFER: Sofa", Vec: vec},
		{Msgid: 21, Groupid: 200, Msgtype: "Wanted", Lat: 52.0, Lng: 0.0, Subject: "WANTED: Sofa", Vec: vec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, nil, "Offer", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(20), results[0].Msgid)
}

func TestVectorSearchWithGroupFilter(t *testing.T) {
	vec := makeTestVec(1.0)

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 30, Groupid: 100, Msgtype: "Offer", Subject: "OFFER: Sofa", Vec: vec},
		{Msgid: 31, Groupid: 200, Msgtype: "Offer", Subject: "OFFER: Sofa", Vec: vec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("sofa", 10, []uint64{200}, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 1)
	assert.Equal(t, uint64(31), results[0].Msgid)
}

func TestVectorSearchLimit(t *testing.T) {
	vec := makeTestVec(1.0)
	entries := make([]embedding.Entry, 10)
	for i := range entries {
		entries[i] = embedding.Entry{
			Msgid: uint64(i + 1), Groupid: 100, Msgtype: "Offer",
			Subject: "OFFER: Item", Vec: vec,
		}
	}
	embedding.Global.SetEntries(entries)
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, vec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("item", 3, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	assert.Len(t, results, 3)
}

func TestVectorSearchSidecarError(t *testing.T) {
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1, Groupid: 100, Msgtype: "Offer", Subject: "test", Vec: makeTestVec(1.0)},
	})
	defer embedding.Global.SetEntries(nil)

	// Start and immediately close a server to get a guaranteed-refused port
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	url := server.URL
	server.Close()

	embedding.SetSidecarURL(url)
	defer embedding.SetSidecarURL("")

	_, err := message.VectorSearch("sofa", 10, nil, "", 0, 0, 0, 0)
	assert.Error(t, err)
}

func TestStoreSetEntriesAndCount(t *testing.T) {
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1}, {Msgid: 2}, {Msgid: 3},
	})
	assert.Equal(t, 3, embedding.Global.Count())

	embedding.Global.SetEntries(nil)
	assert.Equal(t, 0, embedding.Global.Count())
}
