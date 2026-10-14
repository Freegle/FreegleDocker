package microvolunteering

import (
	"encoding/json"
	"strings"

	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// The UxPrompt challenge: a plain-English question for microvolunteers about a UX experiment
// (would this change be better or worse for you, with a picture of now and of the change) or a
// survey of what works best and worst about Freegle. The prompts are rows in ux_prompts, written
// by the batch; answers go to ux_prompt_responses, never to microactions, so a new prompt needs no
// schema change. docs/developers/reference/ux-telemetry.md section 8.
//
// The answers are a reaction gauge with a known status-quo bias. They are shown next to the
// measured result on the SysAdmin UX tab and never decide an experiment.

const ChallengeUxPrompt = "UxPrompt"

// A member is asked at most this often, whatever is open.
const uxPromptMinGapHours = 24

type UxPromptOption struct {
	Key   string `json:"key"`
	Label string `json:"label"`
}

type UxPromptImage struct {
	URL     string `json:"url"`
	Caption string `json:"caption"`
}

type UxPromptChallenge struct {
	ID       uint64           `json:"id"`
	Kind     string           `json:"kind"`
	Question string           `json:"question"`
	Body     string           `json:"body"`
	Options  []UxPromptOption `json:"options"`
	Images   []UxPromptImage  `json:"images"`
}

// getUxPromptChallenge picks an open prompt this member has not answered, the one with the fewest
// answers first so every prompt fills evenly, and nothing within a day of their last answer.
func getUxPromptChallenge(db *gorm.DB, userID uint64) *Challenge {
	var recent int64
	db.Table("ux_prompt_responses").
		Where("userid = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)", userID, uxPromptMinGapHours).
		Count(&recent)
	if recent > 0 {
		return nil
	}

	var row struct {
		ID       uint64 `gorm:"column:id"`
		Kind     string `gorm:"column:kind"`
		Question string `gorm:"column:question"`
		Body     string `gorm:"column:body"`
		Options  string `gorm:"column:options"`
		Images   *string
	}
	res := db.Table("ux_prompts p").
		Select("p.id, p.kind, p.question, p.body, p.options, p.images, (SELECT COUNT(*) FROM ux_prompt_responses r WHERE r.prompt_id = p.id) AS answered").
		Where("p.status = 'open'").
		Where("NOT EXISTS (SELECT 1 FROM ux_prompt_responses r WHERE r.prompt_id = p.id AND r.userid = ?)", userID).
		Where("(SELECT COUNT(*) FROM ux_prompt_responses r WHERE r.prompt_id = p.id) < p.max_responses").
		Order("answered ASC, p.id ASC").
		Limit(1).
		Scan(&row)
	if res.RowsAffected == 0 || row.ID == 0 {
		return nil
	}

	ch := &UxPromptChallenge{ID: row.ID, Kind: row.Kind, Question: row.Question, Body: row.Body, Options: []UxPromptOption{}, Images: []UxPromptImage{}}
	_ = json.Unmarshal([]byte(row.Options), &ch.Options)
	if row.Images != nil {
		_ = json.Unmarshal([]byte(*row.Images), &ch.Images)
	}
	if len(ch.Options) == 0 {
		return nil
	}
	return &Challenge{Type: ChallengeUxPrompt, UxPrompt: ch}
}

// UxPromptAnswer is what the member chose. An experiment prompt sends choice; a survey sends best
// and worst, or choice set to dont_care or dont_understand. Every other key must be one of the
// prompt's options.
type UxPromptAnswer struct {
	Choice string `json:"choice,omitempty"`
	Best   string `json:"best,omitempty"`
	Worst  string `json:"worst,omitempty"`
}

// recordUxPromptResponse validates and stores an answer. The prompt must be open; keys must be its
// own options; a second answer from the same member is ignored, not updated, so nobody can rewrite
// their vote after seeing the tally.
func recordUxPromptResponse(db *gorm.DB, userID uint64, promptID uint64, answer *UxPromptAnswer, comments *string) error {
	if answer == nil {
		return fiber.NewError(fiber.StatusBadRequest, "An answer is required")
	}
	var row struct {
		Kind    string `gorm:"column:kind"`
		Options string `gorm:"column:options"`
	}
	res := db.Table("ux_prompts").Select("kind, options").Where("id = ? AND status = 'open'", promptID).Scan(&row)
	if res.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "That question is no longer open")
	}
	var options []UxPromptOption
	_ = json.Unmarshal([]byte(row.Options), &options)
	valid := map[string]bool{}
	for _, o := range options {
		valid[o.Key] = true
	}

	stored := map[string]string{}
	switch row.Kind {
	case "survey":
		// Either a best and a worst from the options, or one of the two escape answers, which
		// are answers in their own right and are tallied as such.
		if answer.Choice == "dont_care" || answer.Choice == "dont_understand" {
			stored["choice"] = answer.Choice
			break
		}
		if !valid[answer.Best] || !valid[answer.Worst] || answer.Best == answer.Worst {
			return fiber.NewError(fiber.StatusBadRequest, "best and worst must each be one of the options, and differ")
		}
		stored["best"] = answer.Best
		stored["worst"] = answer.Worst
	default:
		if !valid[answer.Choice] {
			return fiber.NewError(fiber.StatusBadRequest, "choice must be one of the options")
		}
		stored["choice"] = answer.Choice
	}
	encoded, _ := json.Marshal(stored)

	var comment interface{}
	if comments != nil {
		c := strings.TrimSpace(*comments)
		if c != "" {
			r := []rune(c)
			if len(r) > 1000 {
				c = string(r[:1000])
			}
			comment = c
		}
	}

	return db.Table("ux_prompt_responses").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"prompt_id": promptID,
		"userid":    userID,
		"answer":    string(encoded),
		"comment":   comment,
	}).Error
}
