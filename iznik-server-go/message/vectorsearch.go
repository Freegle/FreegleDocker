package message

import (
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/utils"
)

const keywordBoostWeight = 0.3

// MinVectorScore is the minimum cosine similarity to include a result.
// nomic-embed-text-v1.5 normalized dot products: random/noise scores ~0.50,
// tangential matches ~0.60, genuine semantic matches 0.70+, exact 0.75+.
const MinVectorScore = 0.65

// VectorSearch performs semantic search with hybrid keyword scoring.
func VectorSearch(term string, limit int, groupids []uint64, msgtype string,
	nelat, nelng, swlat, swlng float32) ([]SearchResult, error) {

	queryVec, err := embedding.EmbedQuery(term)
	if err != nil {
		return nil, err
	}

	// Fetch more than needed so we can re-rank with keyword boost
	vecResults := embedding.Global.Search(queryVec, limit*3, msgtype, groupids,
		swlat, swlng, nelat, nelng)

	queryWords := GetWords(term)

	type scoredResult struct {
		result SearchResult
		score  float32
	}

	scored := make([]scoredResult, 0, len(vecResults))

	for _, vr := range vecResults {
		// Skip results below the minimum similarity threshold
		if vr.Score < MinVectorScore {
			continue
		}

		// Keyword boost: whole-word overlap between query and subject, not substring.
		// A substring match fires for e.g. "portable"/"adjustable" against the query
		// "table" because those contain "table" as a substring, unfairly boosting
		// unrelated results above literal-"table" subjects (Discourse 9585.11).
		// Tokenise the subject the same way we tokenise the query (GetWords strips
		// punctuation, lowercases, and removes common stop-words), then intersect.
		var keywordScore float32
		if len(queryWords) > 0 {
			subjectWords := GetWords(vr.Subject)
			subjectSet := make(map[string]bool, len(subjectWords))
			for _, w := range subjectWords {
				subjectSet[w] = true
			}
			matched := 0
			for _, w := range queryWords {
				if subjectSet[w] {
					matched++
				}
			}
			keywordScore = float32(matched) / float32(len(queryWords))
		}

		hybridScore := vr.Score + keywordScore*keywordBoostWeight

		lat, lng := utils.Blur(vr.Lat, vr.Lng, utils.BLUR_USER)

		sr := SearchResult{
			Msgid:   vr.Msgid,
			Arrival: vr.Arrival,
			Groupid: vr.Groupid,
			Lat:     lat,
			Lng:     lng,
			Word:    term,
			Type:    vr.Msgtype,
			Matchedon: Matchedon{
				Type: "Vector",
				Word: term,
			},
		}

		scored = append(scored, scoredResult{result: sr, score: hybridScore})
	}

	// Sort by hybrid score descending
	for i := 0; i < len(scored)-1; i++ {
		for j := i + 1; j < len(scored); j++ {
			if scored[j].score > scored[i].score {
				scored[i], scored[j] = scored[j], scored[i]
			}
		}
	}

	if len(scored) > limit {
		scored = scored[:limit]
	}

	results := make([]SearchResult, len(scored))
	for i, s := range scored {
		results[i] = s.result
	}

	return results, nil
}
