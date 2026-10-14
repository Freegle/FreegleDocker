package microvolunteering

import (
	"encoding/json"
	"strings"
	"unicode"

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

// A member is asked at most this often, whatever is open. A skip counts: someone who pressed
// "skip this one" has said they do not want a question right now.
const uxPromptMinGapHours = 24

// An open question's free-text answer is this many characters, after trimming.
const (
	uxOpenTextMin = 3
	uxOpenTextMax = 1000
)

// The two ways out of an open question, so nobody has to invent an answer. Their labels come
// from the prompt's own options when it has them.
const (
	uxEscapeNothing        = "nothing"
	uxEscapeDontUnderstand = "dont_understand"
)

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
	// Settings passes the prompt's presentation through: best_label and worst_label for a survey,
	// randomise (show the options in a random order) and preset. Always an object.
	Settings map[string]interface{} `json:"settings"`
}

// openOptions makes sure an open prompt offers both escapes, whatever the row's options say.
func openOptions(options []UxPromptOption) []UxPromptOption {
	have := map[string]bool{}
	for _, o := range options {
		have[o.Key] = true
	}
	out := options
	if !have[uxEscapeNothing] {
		out = append(out, UxPromptOption{Key: uxEscapeNothing, Label: "Nothing comes to mind"})
	}
	if !have[uxEscapeDontUnderstand] {
		out = append(out, UxPromptOption{Key: uxEscapeDontUnderstand, Label: "Don't understand"})
	}
	return out
}

// answeredCount is how many answers a prompt has. A skip is a response row (so the member is not
// asked again) but it is not an answer, so it never counts towards the cap or the ordering.
const answeredCount = "(SELECT COUNT(*) FROM ux_prompt_responses r WHERE r.prompt_id = p.id AND JSON_EXTRACT(r.answer, '$.skipped') IS NULL)"

// getUxPromptChallenge picks an open prompt this member has not answered or skipped, the one with
// the fewest answers first so every prompt fills evenly, and nothing within a day of their last
// answer or skip.
func getUxPromptChallenge(db *gorm.DB, userID uint64) *Challenge {
	var recent int64
	db.Table("ux_prompt_responses").
		Where("userid = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)", userID, uxPromptMinGapHours).
		Count(&recent)
	if recent > 0 {
		return nil
	}

	var row struct {
		ID       uint64  `gorm:"column:id"`
		Kind     string  `gorm:"column:kind"`
		Question string  `gorm:"column:question"`
		Body     string  `gorm:"column:body"`
		Options  string  `gorm:"column:options"`
		Images   *string `gorm:"column:images"`
		Settings *string `gorm:"column:settings"`
	}
	res := db.Table("ux_prompts p").
		Select("p.id, p.kind, p.question, p.body, p.options, p.images, p.settings, "+answeredCount+" AS answered").
		Where("p.status = 'open'").
		Where("NOT EXISTS (SELECT 1 FROM ux_prompt_responses r WHERE r.prompt_id = p.id AND r.userid = ?)", userID).
		Where(answeredCount + " < p.max_responses").
		Order("answered ASC, p.id ASC").
		Limit(1).
		Scan(&row)
	if res.RowsAffected == 0 || row.ID == 0 {
		return nil
	}

	ch := &UxPromptChallenge{ID: row.ID, Kind: row.Kind, Question: row.Question, Body: row.Body, Options: []UxPromptOption{}, Images: []UxPromptImage{}, Settings: map[string]interface{}{}}
	_ = json.Unmarshal([]byte(row.Options), &ch.Options)
	if row.Images != nil {
		_ = json.Unmarshal([]byte(*row.Images), &ch.Images)
	}
	if row.Settings != nil {
		var settings map[string]interface{}
		if json.Unmarshal([]byte(*row.Settings), &settings) == nil && settings != nil {
			ch.Settings = settings
		}
	}
	if row.Kind == "open" {
		ch.Options = openOptions(ch.Options)
	}
	if len(ch.Options) == 0 {
		return nil
	}
	return &Challenge{Type: ChallengeUxPrompt, UxPrompt: ch}
}

// UxPromptAnswer is what the member chose. An experiment prompt sends choice; a survey sends best
// and worst, or choice set to dont_care or dont_understand; an open question sends text, or choice
// set to nothing or dont_understand. Any kind can send skipped, which is recorded so the prompt is
// not offered again and is counted as nothing. Every other key must be one of the prompt's options.
type UxPromptAnswer struct {
	Choice  string `json:"choice,omitempty"`
	Best    string `json:"best,omitempty"`
	Worst   string `json:"worst,omitempty"`
	Text    string `json:"text,omitempty"`
	Skipped bool   `json:"skipped,omitempty"`
}

// cleanOpenText trims an open answer and drops control characters other than a line break or tab,
// so the stored text is plain. It is stored as data and shown escaped; nothing here tries to make
// it safe to render, which is the display's job.
func cleanOpenText(in string) string {
	var b strings.Builder
	for _, r := range in {
		if r == '\n' || r == '\t' || !unicode.IsControl(r) {
			b.WriteRune(r)
		}
	}
	return strings.TrimSpace(b.String())
}

// recordUxPromptResponse validates and stores an answer. The prompt must be open; keys must be its
// own options; a second answer from the same member is ignored, not updated, so nobody can rewrite
// their vote after seeing the tally. A skip takes the member's one place too, so a skipped prompt
// is not offered again.
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

	stored := map[string]interface{}{}
	hasComment := true
	switch {
	case answer.Skipped:
		stored["skipped"] = true
		hasComment = false
	case row.Kind == "survey":
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
	case row.Kind == "open":
		// Free text, or one of the two ways out. The escapes are always allowed, whatever the
		// prompt's options say, so nobody is forced to invent an answer.
		hasComment = false
		if answer.Choice != "" {
			if answer.Choice != uxEscapeNothing && answer.Choice != uxEscapeDontUnderstand {
				return fiber.NewError(fiber.StatusBadRequest, "choice must be nothing or dont_understand")
			}
			stored["choice"] = answer.Choice
			break
		}
		text := cleanOpenText(answer.Text)
		if n := len([]rune(text)); n < uxOpenTextMin || n > uxOpenTextMax {
			return fiber.NewError(fiber.StatusBadRequest, "Please write between 3 and 1000 characters, or choose one of the other answers")
		}
		stored["text"] = text
	default:
		if !valid[answer.Choice] {
			return fiber.NewError(fiber.StatusBadRequest, "choice must be one of the options")
		}
		stored["choice"] = answer.Choice
	}
	encoded, _ := json.Marshal(stored)

	var comment interface{}
	if comments != nil && hasComment {
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
