import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxExperimentRow from '~/modtools/components/ModSysAdminUxExperimentRow.vue'

const result = (over = {}, primary = {}) => ({
  arms: {
    control: { starts: 200, completes: 92 },
    b: { starts: 210, completes: 107 },
  },
  primary: {
    b: {
      control_rate: 0.46,
      variant_rate: 0.51,
      diff_points: 5.4,
      p_better: 0.91,
      enough: true,
      share_off: false,
      ...primary,
    },
  },
  guardrails: [],
  ...over,
})

const experiment = (over = {}) => ({
  id: 3,
  flow: 'give',
  name: 'give-photos-skip',
  title: 'Make skipping photos obvious',
  status: 'running',
  result: result(),
  ...over,
})

const make = (props = {}) =>
  mount(ModSysAdminUxExperimentRow, {
    props: { experiment: experiment(), ...props },
  })

describe('ModSysAdminUxExperimentRow', () => {
  it('is one button, with the status, the title, the funnel and the result line', () => {
    const w = make()
    expect(w.element.tagName).toBe('BUTTON')
    expect(w.attributes('type')).toBe('button')
    expect(w.findAll('button')).toHaveLength(1)
    expect(w.find('.status').text()).toBe('Running')
    expect(w.find('.title').text()).toBe('Make skipping photos obvious')
    expect(w.find('.funnel').text()).toBe('Offering')
    expect(w.find('.line').text()).toBe(
      'Looking better: 51% finished against 46% (+5.4 points).'
    )
  })

  it('says it is open or closed to a screen reader, and which block it opens', () => {
    const closed = make({ controls: 'ux-exp-3' })
    expect(closed.attributes('aria-expanded')).toBe('false')
    expect(closed.attributes('aria-controls')).toBe('ux-exp-3')
    const open = make({ open: true })
    expect(open.attributes('aria-expanded')).toBe('true')
    expect(open.classes()).toContain('open')
  })

  it('asks to be opened or closed when it is pressed', async () => {
    const w = make()
    await w.trigger('click')
    expect(w.emitted('toggle')).toHaveLength(1)
  })

  it('names each status in a word', () => {
    const cases = [
      [{ status: 'draft', result: null }, 'Not started'],
      [{ status: 'running' }, 'Running'],
      [{ status: 'adopted' }, 'Kept'],
      [
        { status: 'adopted', holdout_until: '2026-10-25 09:00:00' },
        'Kept for now',
      ],
      [{ status: 'closed' }, 'Kept, cleaned up'],
      [{ status: 'reverted' }, 'Taken off'],
      [{ status: 'inconclusive' }, 'Could not tell'],
      [{ status: 'stopped' }, 'Stopped'],
    ]
    for (const [over, word] of cases) {
      expect(
        make({ experiment: experiment(over) })
          .find('.status')
          .text()
      ).toBe(word)
    }
  })

  it('uses the short name of each funnel, and the key for a funnel it does not know', () => {
    expect(
      make({ experiment: experiment({ flow: 'chat_outcome' }) })
        .find('.funnel')
        .text()
    ).toBe('Marking taken')
    expect(
      make({ experiment: experiment({ flow: 'reply' }) })
        .find('.funnel')
        .text()
    ).toBe('Replying')
    expect(
      make({ experiment: experiment({ flow: 'newthing' }) })
        .find('.funnel')
        .text()
    ).toBe('newthing')
  })

  it('says a draft has not started', () => {
    const w = make({
      experiment: experiment({ status: 'draft', result: null }),
    })
    expect(w.find('.line').text()).toBe('Not started.')
  })

  describe('the extra chips', () => {
    const worrying = result({
      guardrails: [
        {
          arm: 'b',
          name: 'submit_failed',
          label: 'the share of submissions that failed',
          control_rate: 0.05,
          variant_rate: 0.09,
          p_worse: 0.7,
        },
      ],
    })

    it('flags a guardrail of a running experiment that may be worse, once', () => {
      const w = make({ experiment: experiment({ result: worrying }) })
      const chip = w.find('.watch')
      expect(chip.text()).toBe('Watch: the share of submissions that failed')
      // The chip says it, so the line does not say it again.
      expect(w.find('.line-text').text()).not.toContain('Watch')
      expect(w.text().match(/Watch/g)).toHaveLength(1)
    })

    it('draws no watch chip for a guardrail that is fine, or for a finished experiment', () => {
      expect(make().find('.watch').exists()).toBe(false)
      expect(
        make({
          experiment: experiment({ status: 'adopted', result: worrying }),
        })
          .find('.watch')
          .exists()
      ).toBe(false)
    })

    it('flags a cleanup that is overdue', () => {
      const w = make({
        experiment: experiment({ status: 'adopted', cleanup_overdue: true }),
      })
      expect(w.find('.overdue').text()).toBe('Clean up the code')
    })

    it('flags a request the batch has not answered', () => {
      expect(make().find('.waiting').exists()).toBe(false)
      expect(make({ waiting: true }).find('.waiting').text()).toBe(
        'Waiting for the batch'
      )
    })
  })

  it('shows no old figures for an experiment that was started again', () => {
    // start() resets result to the start facts; the reads of the run before are still in the table.
    const w = make({
      experiment: experiment({
        result: { mde_points: 4.2 },
        latest_read: null,
      }),
    })
    expect(w.find('.line').text()).toBe(
      'No count yet. The first one comes within the hour.'
    )
  })
})
