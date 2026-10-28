import type { TutorialGoal, TutorialResponse, TutorialStep } from '@/api/types'

export function aStep(overrides: Partial<TutorialStep> = {}): TutorialStep {
  return {
    key: 'refuel',
    number: 4,
    sender: '+48 555 010 247',
    sms: ['New number, same me. Don’t ask.', 'The tank is almost empty. Tap “Fill up” so the car keeps driving, even when you’re away. — K.'],
    reply: 'OK, filling up',
    target: 'refuel',
    acknowledged: false,
    optional: false,
    decline: null,
    ...overrides,
  }
}

export function aGoal(overrides: Partial<TutorialGoal> = {}): TutorialGoal {
  return {
    key: 'refuel',
    number: 4,
    phase: 1,
    title: 'Fill the tank',
    waiting: null,
    target: 'refuel',
    optional: false,
    reward: { cash_cents: 9000, car_xp: 0, xp: 180, part: null, garage: null },
    ...overrides,
  }
}

/** The answer of the tutorial with a step waiting; `current` null gives a tutorial with nothing to show. */
export function aTutorial(current: TutorialStep | null = aStep(), overrides: Partial<TutorialResponse['data']> = {}): TutorialResponse {
  return { data: { status: 'in_progress', total: 5, done: 3, current, goal: aGoal(), rewarded: [], ...overrides } }
}
