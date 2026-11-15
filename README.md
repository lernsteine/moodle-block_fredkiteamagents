# FredKI Team Agents (block_fredkiteamagents)

AI agents take part in a Moodle course like participants. Each agent has its own Moodle account, is enrolled
in the course with the role "AI agent" and knows the content of its own knowledge folder in the course.
The AI does not replace the people in the course: the agents bring in questions, perspectives and impulses
so that the people talk more with each other.

Made for the course format FredKI learning space (`format_fredki`), which is required.

## Two ways to use AI in a course

- **Learning companion:** accompanies each learner privately. It answers "Ask FredKI …" in the learning space,
  asks the question of the day, answers open questions, and learners can write to it in the Moodle messages.
- **Expert round:** several agents with different roles – Expert, Critic, Optimist, Practitioner, Moderator and
  Supervisor. People call one agent with `@Name` in a forum post, or ask all active agents for their view
  on a conversation. Further templates are managed centrally in FredKI and appear in the block automatically.

## What happens when a trainer adds an agent

1. The site account of the template is created on first use (cannot log in, name with "(AI)", portrait of the
   template) and enrolled in the course with the role "AI agent".
2. A Moodle folder "Knowledge: <Agent>" is created in the course, hidden from learners. Trainers put the
   material for the agent in this folder.
3. In the background the agent and its knowledge base are built in FredKI, filed per site and course. The course
   page does not wait for it; the block shows the progress.
4. Files added to or removed from the folder are transferred shortly afterwards. Network failures are retried.

Trainers need no access to FredKI: everything is set up in Moodle.

## When an agent speaks

Moodle decides, FredKI only answers:

- `@Name` in a post written by a person: that agent answers in the conversation.
- Asking all agents on a card of the learning space: every active agent answers the latest post of a person.
- Never on posts of other agents or FredKI, at most one answer per agent and post. Trainers can set a daily
  limit per agent (unlimited by default) and pause an agent.

The conversation is sent without names ("Person A", "Person B"). Moodle puts the first names back before the
answer is published, so the agents address people personally while FredKI never sees a name.

## Requirements

- Moodle 4.5
- FredKI The Agent (`block_fredkitheagent`), installed and activated: Team Agents uses its activation of the site
  and its connection to the FredKI licence server.
- The course format FredKI learning space (`format_fredki`): the learning companion and the expert round appear
  there. In other course formats only mentions with `@Name` in the forum and messages to the learning companion work.
- Cron running every minute.

## Costs

- The plugin is free and open source (GNU GPL v3 or later). It uses the hosted FredKI service by lernsteine.com.
- **Free, permanently:** one course with Team Agents and the FredKI learning space, within the free package of FredKI
  The Agent (200 answered messages per month). No payment method is needed.
- **Team Agents packages** with more courses and messages are offered as paid options on the plugin's page in Moodle
  Marketplace. After the purchase a licence key is sent by e-mail; the site administrator enters it on the activation
  page of FredKI The Agent (version 1.8.0 or later). When a package ends, the site continues in the free package.

## Privacy

- Conversation text and the files of the knowledge folders are sent to FredKI, without names of people.
- The plugin stores no personal data of people. The Privacy API is implemented.

## License

2026 lernsteine.com – GNU GPL v3 or later.
