package message

import (
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/utils"
)

const keywordBoostWeight = 0.3

// MinVectorScore is the minimum combined (subject+body) cosine score to
// include a result. nomic-embed-text-v1.5 normalized dot products: random
// noise ~0.50, tangential ~0.60, genuine semantic matches 0.70+, exact 0.75+.
const MinVectorScore = 0.65

// VectorSearch performs semantic search with hybrid keyword scoring.
func VectorSearch(term string, limit int, groupids []uint64, msgtype string,
	nelat, nelng, swlat, swlng float32) ([]SearchResult, error) {

	queryVec, err := embedding.EmbedQuery(term)
	if err != nil {
		return nil, err
	}

	// Fetch more than needed so we can re-rank with keyword boost.
	vecResults := embedding.Global.Search(queryVec, limit*3, msgtype, groupids,
		swlat, swlng, nelat, nelng)

	queryWords := GetWords(term)

	type scoredResult struct {
		result SearchResult
		score  float32
	}

	scored := make([]scoredResult, 0, len(vecResults))

	for _, vr := range vecResults {
		// Combined score below threshold → drop. Keyword boost doesn't
		// rescue results below the semantic floor; it only re-orders.
		if vr.Score < MinVectorScore {
			continue
		}

		var keywordScore float32
		if len(queryWords) > 0 {
			// Whole-word match: tokenise the subject with the same rules
			// GetWords uses for the query so "table" ≠ "portable".
			subjectWords := GetWords(vr.Subject)
			subjectSet := make(map[string]struct{}, len(subjectWords))
			for _, w := range subjectWords {
				subjectSet[w] = struct{}{}
			}

			matched := 0
			for _, w := range queryWords {
				if _, ok := subjectSet[w]; ok {
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

	// Sort by hybrid score descending.
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
