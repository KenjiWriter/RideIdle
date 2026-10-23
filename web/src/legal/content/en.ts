import type { LegalTexts } from '../types'

const texts: LegalTexts = {
  terms: {
    title: 'Terms of service',
    intro:
      'These terms apply to RideIdle, an idle racing game that runs in your browser. Please read them together with the privacy policy. The operator of the game is named at the top of this page.',
    sections: [
      {
        id: 'scope',
        heading: 'Who these terms are for',
        paragraphs: [
          'By creating a driver, signing in or using RideIdle in any other way you agree to these terms. If you do not agree, please do not use the game.',
          'If a part of these terms cannot apply to you because of mandatory law where you live, the rest still applies.',
        ],
      },
      {
        id: 'age',
        heading: 'Minimum age',
        paragraphs: [
          'You must be at least {minAge} years old to use RideIdle. When you secure your account you are asked to confirm your age, and we save when you did.',
          'If we learn that someone younger has an account, we may delete it.',
        ],
      },
      {
        id: 'account',
        heading: 'Your driver and your account',
        paragraphs: [
          'You start as a guest: you choose a driver name and the game creates a guest account without asking for any personal data. A guest account is deleted, with everything in it, after {guestDays} days without activity.',
          'You can secure your account with an e-mail address, Google, Facebook or Apple. A secured account does not expire and can be opened on another device. You can also keep a recovery code. Anyone who has your recovery code or a sign-in link can get into your account, so keep them to yourself.',
          'Choose a driver name that is not offensive and does not pretend to be someone else. We may change or remove a name that breaks this rule.',
          'You are responsible for what happens under your account. Give us true information when you secure it.',
        ],
      },
      {
        id: 'fair-play',
        heading: 'Fair play',
        paragraphs: [
          'The server decides every result in the game. Please play the game as it is meant to be played.',
          'Do not cheat, do not use bots or scripts to play for you, do not exploit mistakes in the game on purpose, do not try to get into other people’s accounts and do not disturb the service for others. If you find a mistake, tell us instead of using it.',
          'If a mistake or unfair play gave someone cash, experience or anything else they should not have, we may correct it.',
        ],
      },
      {
        id: 'virtual-items',
        heading: 'Virtual items',
        paragraphs: [
          'Cash, experience, cars and parts in the game are part of the game. They have no value outside it, cannot be exchanged for money and do not become your property.',
          'RideIdle is free at the moment and has no paid features. If that changes, we will tell you, and separate terms will apply to purchases.',
        ],
      },
      {
        id: 'service',
        heading: 'The game can change',
        paragraphs: [
          'RideIdle is still being developed. We may add, change or remove features, and the game may be unavailable from time to time.',
          'Your progress is kept on our servers, but we cannot promise that it will never be lost or changed. Where it is needed to fix a mistake, we may adjust balances or progress and, where it is reasonable, say why.',
        ],
      },
      {
        id: 'ending',
        heading: 'Ending your use',
        paragraphs: [
          'You can stop playing at any time. To have your account deleted, write to the contact address at the top of this page.',
          'We may suspend or delete an account that breaks these terms. We delete guest accounts that are inactive for {guestDays} days.',
        ],
      },
      {
        id: 'liability',
        heading: 'Our responsibility',
        paragraphs: [
          'We are responsible as the law requires. Nothing in these terms limits a right that you have by law and that cannot be limited, for example as a consumer, or our liability for intent, gross negligence or harm to life or health.',
          'Beyond that, and as far as the law allows, we are not responsible for indirect losses, such as lost game progress, because the game is free and is offered for entertainment.',
        ],
      },
      {
        id: 'law',
        heading: 'Law',
        paragraphs: ['The consumer protection rules of the country where you live, which cannot be waived, continue to apply to you.'],
      },
      {
        id: 'changes',
        heading: 'Changes to these terms',
        paragraphs: [
          'We may change these terms, for example when the game changes. The date at the top shows the version. When a change is important, we will say so in the game. If you keep using the game after a change, the new version applies to you; if you do not agree with it, you can stop playing and ask us to delete your account.',
        ],
      },
      {
        id: 'contact',
        heading: 'Contact',
        paragraphs: ['Questions about these terms: write to the contact address at the top of this page.'],
      },
    ],
  },
  privacy: {
    title: 'Privacy policy',
    intro:
      'This policy explains what personal data RideIdle uses, why, for how long, and what you can do about it. We wrote it in plain words; if something is unclear, ask us.',
    sections: [
      {
        id: 'controller',
        heading: 'Who is responsible',
        paragraphs: [
          'The operator named at the top of this page decides why and how your data is used (the controller). You can reach them at the contact address given there, also with any question or request about your data.',
        ],
      },
      {
        id: 'data',
        heading: 'What data we use',
        paragraphs: [
          'As a guest: the driver name you choose (other drivers can see it), the language of the game, your progress (cars, runs, cash, experience and their history), the time of your last activity and a device token. The token is a random secret kept in your browser; we keep only a fingerprint (hash) of it.',
          'When you secure your account: your e-mail address, or the identifier that Google, Facebook or Apple gives us for your account together with the e-mail address they report. The game shows an address only in a shortened form, like t***@example.com.',
          'We also save when you accepted the terms and confirmed your age, and which version of the terms you accepted. If you create a recovery code, we keep only a fingerprint of it.',
          'For sign-in links and sign-ins with a provider we keep a short-lived record: a fingerprint of the secret, your e-mail address and what it is for. It is deleted soon after it expires or is used.',
          'To stop abuse we count requests by IP address, and by a fingerprint of the e-mail address, for up to an hour. Our servers and hosting may also keep technical logs, such as the IP address and the time of a request.',
          'We do not use advertising, analytics or tracking tools, and we do not sell your data.',
        ],
      },
      {
        id: 'why',
        heading: 'Why we use it, and on what basis',
        paragraphs: [
          'To give you the game and your account: the driver, your progress, signing in and the e-mails you ask for, for example a sign-in link. Basis: it is needed to provide the game you asked for (Article 6(1)(b) of the GDPR).',
          'To keep the game safe and fair, to stop abuse and cheating, and to fix mistakes. Basis: our legitimate interest in running a safe game (Article 6(1)(f) of the GDPR).',
          'To be able to show that you accepted the terms and confirmed your age. Basis: our legitimate interest in being able to show it (Article 6(1)(f) of the GDPR).',
        ],
      },
      {
        id: 'recipients',
        heading: 'Who receives it',
        paragraphs: [
          'Companies that help us run the game: hosting and infrastructure, and the service that delivers e-mails. They may use the data only on our instructions.',
          'Google, Facebook (Meta) or Apple, only if you choose to sign in with them. We do not send them anything about your game. They tell us your identifier and e-mail address, and they use your data under their own privacy policies.',
          'We do not give your data to anyone else, unless the law requires it.',
        ],
      },
      {
        id: 'transfers',
        heading: 'Outside your country',
        paragraphs: [
          'Some of these companies may process data outside the European Economic Area or outside your country. Where that happens we rely on the safeguards the law requires, for example standard contractual clauses.',
        ],
      },
      {
        id: 'retention',
        heading: 'How long we keep it',
        paragraphs: [
          'A guest account, with its game data, is deleted after {guestDays} days without activity.',
          'A secured account is kept while it exists. You can ask us to delete it at any time.',
          'Sign-in links and sign-in records are deleted soon after they expire or are used. Request counters for abuse prevention last up to an hour. Technical logs are kept only as long as security needs them.',
        ],
      },
      {
        id: 'rights',
        heading: 'Your rights',
        paragraphs: [
          'You can ask us for access to your data, to correct it, to delete it, to limit its use and to receive it in a portable format. You can also object to the use that is based on our legitimate interest.',
          'Write to the contact address at the top of this page. We answer within one month. To protect your account we may ask you to prove that it is yours, for example by a link sent to the e-mail address of the account. For now, requests are handled by e-mail.',
          'You can also complain to the data protection authority in your country.',
        ],
      },
      {
        id: 'storage',
        heading: 'What stays in your browser',
        paragraphs: [
          'RideIdle does not use cookies. It saves a few things in the local storage of your browser: the device token, the language you chose, a marker of the last state of your run that you have seen, whether you postponed the window that asks you to save your garage, and, for a few minutes while you sign in with a provider, a one-time secret. The game needs them to work, and they are not used to track you.',
          'If you clear your browser data, you are signed out. A guest account that has no recovery code cannot be opened again.',
        ],
      },
      {
        id: 'age',
        heading: 'Age',
        paragraphs: ['The game is for people who are at least {minAge} years old. If you think someone younger has an account, write to us and we will delete it.'],
      },
      {
        id: 'security',
        heading: 'Security',
        paragraphs: [
          'We keep the secrets of the game (recovery codes, sign-in links, device tokens) only as fingerprints, and we limit how often they can be tried. No system is perfectly secure, so keep your recovery code and sign-in links to yourself.',
        ],
      },
      {
        id: 'changes',
        heading: 'Changes to this policy',
        paragraphs: ['We may change this policy. The date at the top shows the version. When a change is important, we will say so in the game.'],
      },
      {
        id: 'contact',
        heading: 'Contact',
        paragraphs: ['Questions or requests about your data: write to the contact address at the top of this page.'],
      },
    ],
  },
}

export default texts
