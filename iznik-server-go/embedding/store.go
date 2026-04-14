package embedding

import (
	"encoding/binary"
	"fmt"
	"math"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
)

// EmbeddingDim is 256-dim Matryoshka truncation of nomic-embed-text-v1.5.
const EmbeddingDim = 256

// Entry holds one message's embedding and metadata for filtering.
type Entry struct {
	Msgid   uint64
	Groupid uint64
	Msgtype string
	Lat     float64
	Lng     float64
	Subject string
	Arrival time.Time
	Vec     [EmbeddingDim]float32
}

// Store is the in-memory embedding index.
type Store struct {
	mu      sync.RWMutex
	entries []Entry
}

// Global is the singleton embedding store.
var Global Store

// StartRefresh begins periodic loading of embeddings from the database.
func StartRefresh(interval time.Duration) {
	if err := Global.Load(); err != nil {
		fmt.Printf("WARNING: initial embedding load failed: %v\n", err)
	}

	go func() {
		ticker := time.NewTicker(interval)
		for range ticker.C {
			if err := Global.Load(); err != nil {
				fmt.Printf("WARNING: embedding refresh failed: %v\n", err)
			}
		}
	}()
}

// Load reads all embeddings + spatial metadata from DB.
func (s *Store) Load() error {
	db := database.DBConn

	type row struct {
		Msgid     uint64    `gorm:"column:msgid"`
		Embedding []byte    `gorm:"column:embedding"`
		Groupid   uint64    `gorm:"column:groupid"`
		Msgtype   string    `gorm:"column:msgtype"`
		Lat       float64   `gorm:"column:lat"`
		Lng       float64   `gorm:"column:lng"`
		Subject   string    `gorm:"column:subject"`
		Arrival   time.Time `gorm:"column:arrival"`
	}

	var rows []row
	result := db.Raw(`
		SELECT me.msgid, me.embedding,
		       ms.groupid, ms.msgtype,
		       ST_Y(ms.point) as lat, ST_X(ms.point) as lng,
		       m.subject, ms.arrival
		FROM messages_embeddings me
		INNER JOIN messages_spatial ms ON ms.msgid = me.msgid
		INNER JOIN messages m ON m.id = me.msgid
		WHERE ms.successful = 0 AND ms.promised = 0
	`).Scan(&rows)

	if result.Error != nil {
		return fmt.Errorf("query: %w", result.Error)
	}

	entries := make([]Entry, 0, len(rows))
	for _, r := range rows {
		if len(r.Embedding) != EmbeddingDim*4 {
			continue // wrong size, skip
		}

		var e Entry
		e.Msgid = r.Msgid
		e.Groupid = r.Groupid
		e.Msgtype = r.Msgtype
		e.Lat = r.Lat
		e.Lng = r.Lng
		e.Subject = r.Subject
		e.Arrival = r.Arrival

		// Decode float32 from little-endian binary
		for i := 0; i < EmbeddingDim; i++ {
			bits := binary.LittleEndian.Uint32(r.Embedding[i*4 : (i+1)*4])
			e.Vec[i] = math.Float32frombits(bits)
		}

		entries = append(entries, e)
	}

	s.mu.Lock()
	s.entries = entries
	s.mu.Unlock()

	fmt.Printf("Loaded %d embeddings into memory\n", len(entries))
	return nil
}

// VectorSearchResult from vector search.
type VectorSearchResult struct {
	Msgid   uint64    `json:"id"`
	Groupid uint64    `json:"groupid"`
	Msgtype string    `json:"type"`
	Lat     float64   `json:"lat"`
	Lng     float64   `json:"lng"`
	Score   float32   `json:"score"`
	Subject string    `json:"-"` // Used for hybrid keyword scoring, not serialized
	Arrival time.Time `json:"-"`
}

// Search performs brute-force cosine similarity (dot product on normalized vectors).
func (s *Store) Search(query []float32, limit int, msgtype string, groupids []uint64,
	swlat, swlng, nelat, nelng float32) []VectorSearchResult {

	s.mu.RLock()
	defer s.mu.RUnlock()

	groupSet := make(map[uint64]bool, len(groupids))
	for _, g := range groupids {
		groupSet[g] = true
	}
	hasGroupFilter := len(groupids) > 0
	hasBoxFilter := nelat != 0 || nelng != 0 || swlat != 0 || swlng != 0

	type scored struct {
		idx   int
		score float32
	}

	results := make([]scored, 0, len(s.entries))

	for i := range s.entries {
		e := &s.entries[i]

		if msgtype == "Offer" && e.Msgtype != "Offer" {
			continue
		}
		if msgtype == "Wanted" && e.Msgtype != "Wanted" {
			continue
		}
		if hasGroupFilter && !groupSet[e.Groupid] {
			continue
		}
		if hasBoxFilter {
			lat := float32(e.Lat)
			lng := float32(e.Lng)
			if lat < swlat-0.02 || lat > nelat+0.02 || lng < swlng-0.02 || lng > nelng+0.02 {
				continue
			}
		}

		// Dot product (vectors are pre-normalized)
		var dot float32
		for j := 0; j < EmbeddingDim; j++ {
			dot += query[j] * e.Vec[j]
		}

		results = append(results, scored{idx: i, score: dot})
	}

	// Partial sort: find top-K
	if len(results) > limit {
		for i := 0; i < limit && i < len(results); i++ {
			maxIdx := i
			for j := i + 1; j < len(results); j++ {
				if results[j].score > results[maxIdx].score {
					maxIdx = j
				}
			}
			results[i], results[maxIdx] = results[maxIdx], results[i]
		}
		results = results[:limit]
	}

	out := make([]VectorSearchResult, len(results))
	for i, r := range results {
		e := &s.entries[r.idx]
		out[i] = VectorSearchResult{
			Msgid:   e.Msgid,
			Groupid: e.Groupid,
			Msgtype: e.Msgtype,
			Lat:     e.Lat,
			Lng:     e.Lng,
			Score:   r.score,
			Subject: e.Subject,
			Arrival: e.Arrival,
		}
	}

	return out
}

// Count returns the number of loaded embeddings.
func (s *Store) Count() int {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return len(s.entries)
}
