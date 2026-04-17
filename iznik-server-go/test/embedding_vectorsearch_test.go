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

func TestEmbedBatch(t *testing.T) {
	vec1 := makeTestVec(1.0)
	vec2 := makeTestVec(2.0)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		type resp struct {
			Embeddings [][]float32 `json:"embeddings"`
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp{Embeddings: [][]float32{vec1[:], vec2[:]}})
	}))
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := embedding.EmbedBatch([]string{"chair", "table"})
	require.NoError(t, err)
	require.Len(t, results, 2)
	assert.Equal(t, vec1[0], results[0][0])
	assert.Equal(t, vec2[0], results[1][0])
}

func TestEmbedBatchEmpty(t *testing.T) {
	results, err := embedding.EmbedBatch([]string{})
	require.NoError(t, err)
	assert.Nil(t, results)
}

func TestStoreSetEntriesAndCount(t *testing.T) {
	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 1}, {Msgid: 2}, {Msgid: 3},
	})
	assert.Equal(t, 3, embedding.Global.Count())

	embedding.Global.SetEntries(nil)
	assert.Equal(t, 0, embedding.Global.Count())
}

// TestVectorSearchKeywordBoostWholeWord reproduces Neville's report (Discourse 9585.11):
// searching for "table" ranked subjects like "Portable lamp" and "Adjustable desk"
// ABOVE subjects containing the literal word "table", because the keyword boost was a
// substring match (strings.Contains) — "portable"/"adjustable" contain the substring
// "table" and so received the same 0.3 boost as literal matches.
//
// The boost must only fire on whole-word matches. To prove this, we give "portable"
// and "adjustable" a slightly HIGHER cosine than the literal-table subjects. Without
// the fix, substring contains() fires for all four → all get equal boost → the higher-
// cosine substring-only items win. With the fix, only literal "table" subjects are
// boosted → they overcome the small cosine deficit and rank top.
func TestVectorSearchKeywordBoostWholeWord(t *testing.T) {
	queryVec := makeTestVec(1.0)

	// Literal "table" subjects get the query's own vector — highest possible cosine (1.0).
	// Substring-only subjects get a slightly different vector — still above the 0.65
	// threshold, but lower than 1.0. The current (buggy) substring-match boost gives all
	// four candidates the same +0.3; since our substring-only vectors are deliberately
	// close to the query, without the fix the selection sort is order-sensitive.
	// To make the failure unambiguous we use a vector for the substring-only subjects
	// that has an EVEN HIGHER dot product with the query than the query itself —
	// achieved by scaling up so the un-normalised dot exceeds 1.
	//
	// Simpler approach: give literal-table subjects a vector that yields cosine 0.80
	// with the query, and substring-only subjects a vector that yields cosine 0.95.
	// Without the fix: 0.80 + 0.30 = 1.10 vs 0.95 + 0.30 = 1.25 → substring wins.
	// With the fix:    0.80 + 0.30 = 1.10 vs 0.95 + 0.00 = 0.95 → literal wins.
	literalVec := mixVec(queryVec, makeTestVec(2.5), 0.80) // cosine ~0.80 with query
	substrVec := mixVec(queryVec, makeTestVec(2.5), 0.95)  // cosine ~0.95 with query

	embedding.Global.SetEntries([]embedding.Entry{
		{Msgid: 100, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Table (oak)", Vec: literalVec},
		{Msgid: 101, Groupid: 1, Msgtype: "Offer", Subject: "WANTED: table lamp", Vec: literalVec},
		{Msgid: 102, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Portable gas heater", Vec: substrVec},
		{Msgid: 103, Groupid: 1, Msgtype: "Offer", Subject: "OFFER: Adjustable office chair", Vec: substrVec},
	})
	defer embedding.Global.SetEntries(nil)

	server := mockSidecarReturning(t, queryVec[:])
	defer server.Close()
	embedding.SetSidecarURL(server.URL)
	defer embedding.SetSidecarURL("")

	results, err := message.VectorSearch("table", 10, nil, "", 0, 0, 0, 0)
	require.NoError(t, err)
	require.Len(t, results, 4, "all candidates pass the 0.65 vector threshold")

	// The two literal-"table" subjects must rank above the substring-only ones,
	// despite the substring-only ones having a HIGHER raw cosine score. This is
	// only true if the keyword boost fires on whole-word matches and NOT on
	// substring-only matches.
	top := map[uint64]bool{results[0].Msgid: true, results[1].Msgid: true}
	order := [...]uint64{results[0].Msgid, results[1].Msgid, results[2].Msgid, results[3].Msgid}
	assert.True(t, top[100], "literal 'Table' subject (100) must rank top-2, got order: %v", order)
	assert.True(t, top[101], "literal 'table' subject (101) must rank top-2, got order: %v", order)

	bottom := map[uint64]bool{results[2].Msgid: true, results[3].Msgid: true}
	assert.True(t, bottom[102], "'Portable' (102) must not be keyword-boosted — got order: %v", order)
	assert.True(t, bottom[103], "'Adjustable' (103) must not be keyword-boosted — got order: %v", order)
}

// mixVec returns a unit vector that blends `base` toward `other` so that the dot
// product with `base` is approximately `targetCosine`. Both inputs must be unit-norm.
func mixVec(base, other [embedding.EmbeddingDim]float32, targetCosine float32) [embedding.EmbeddingDim]float32 {
	// v = a*base + b*other, with a = targetCosine, b chosen so |v|=1.
	// base and other are unit vectors; assume orthogonal enough for this blend to
	// yield dot(v, base) ≈ a. Normalise afterwards to keep it a unit vector.
	var v [embedding.EmbeddingDim]float32
	var norm float32
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] = targetCosine*base[i] + (1-targetCosine)*other[i]
		norm += v[i] * v[i]
	}
	n := float32(math.Sqrt(float64(norm)))
	for i := 0; i < embedding.EmbeddingDim; i++ {
		v[i] /= n
	}
	return v
}
