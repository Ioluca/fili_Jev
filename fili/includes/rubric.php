<?php
/**
 * The questions, in English because Jev reads English best and reads it literally.
 *
 * Version 3: rewritten twice against real rejected proposals. On the site it was tuned on,
 * the share of proposals worth keeping went from 64% to 91% between version 2 and this one.
 * Rules a machine can verify (verbs, fragments, boilerplate) are NOT here: they live in
 * Fili_Text::malformed(), because asking a model to re-check what code guarantees wastes it.
 */
return array(
	'link'   => "Answer yes only if BOTH hold. (1) The source says something substantive about the specific subject named in the title of targets[{n}]: it names that subject and adds information about it, not just a passing word match. (2) The text that says it is real content.\nAnswer no when:\n- only a broad category or common word is shared;\n- the target covers a different version, edition or year of the thing discussed;\n- the source names a model, a product or one single step, while targets[{n}] is a different object that merely uses it;\n- the source and targets[{n}] are the same piece published twice, or two tellings of the same single announcement: those are duplicates to merge, not pages to link;\n- the source merely mentions a word that appears in the title of targets[{n}] while being about something else entirely.",

	'anchor' => "Which phrase, copied verbatim from the source, is a natural anchor for a link to targets[{n}]?\n\nThe candidate phrases were already checked by code: each exists word for word in the source, outside any existing link, heading, quote, caption, code block or shortcode, contains no verb, and neither begins nor ends with a preposition or an article. Do not re-check those things. Judge one thing only: does the phrase NAME the specific subject of targets[{n}]?\n\nAn anchor NAMES a thing. Prefer the proper name of a product, model, company, tool or person; or the exact topic stated in the title of targets[{n}] when the words match closely.\n\nAnswer none when the best candidate is any of these:\n- a standing theme of this site rather than the name of one thing{themes}. Such phrases sit in dozens of posts, so they point at the whole archive, not at targets[{n}]. Answer none even when the target's title contains the same words, unless the target is precisely and only about that one thing;\n- a description of an event instead of the name of a thing;\n- a broad category that would fit many pages;\n- a phrase naming a different version, edition or year from the one targets[{n}] is about.\nIf no candidate names the specific subject, answer none. Answering none is the correct answer most of the time.",

	// Three questions were tried for telling a rewrite from a follow-up. Two were useless
	// ("the second states a new number": always yes; "everything is already in the first":
	// always no). This one spreads its answers, so it is the one that stayed.
	'pairs'  => array(
		'same_news'    => 'Both pieces report the same single announcement, launch or event. Not merely the same company, product family or topic: the same one piece of news.',
		'same_content' => 'The two pieces say substantially the same things, with the same facts and the same conclusion, so a reader gains nothing from reading the second after the first.',
		'later_event'  => 'The second piece reports something that happened after the first piece was published: a reaction, a correction, a price change, a further announcement. Retelling the same event in different words is not a later development.',
	),
);
