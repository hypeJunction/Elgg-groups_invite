<?php

$guid = get_input('guid');
$group = get_entity($guid);
$inviter = elgg_get_logged_in_user_entity();
$invitee_guids = get_input('invitee_guids', array());
$emails = (string) get_input('emails', '');
$resend = get_input('resend', false);
$add = get_input('invite_action') == 'add';
$message = get_input('message', '');

if (!$group instanceof ElggGroup) {
	register_error(elgg_echo('groups:invite:not_found'));
	forward(REFERRER);
}

$skipped = 0;
$invited = 0;
$added = 0;
$error = 0;

if ($invitee_guids && !is_array($invitee_guids)) {
	$invitee_guids = string_to_tag_array($invitee_guids);
}

$emails = explode(PHP_EOL, $emails);

$site = elgg_get_site_entity();
$notification_params = array(
	'inviter' => elgg_view('output/url', array(
		'text' => $inviter->getDisplayName(),
		'href' => $inviter->getURL(),
	)),
	'group' => elgg_view('output/url', array(
		'text' => $group->getDisplayName(),
		'href' => $group->getURL(),
	)),
	'site' => elgg_view('output/url', array(
		'text' => $site->getDisplayName(),
		'href' => $site->getURL(),
	)),
	'message' => ($message) ? elgg_echo('groups:invite:notify:message', array($message)) : '',
);
$subject = elgg_echo('groups:invite:notify:subject', array($group->getDisplayName()));
$body = elgg_echo('groups:invite:notify:body', $notification_params);

foreach ($emails as $email) {
	if (empty($email)) {
		continue;
	}
	$email = trim($email);
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$error++;
		continue;
	}
	$users = get_user_by_email($email);
	if ($users) {
		$invitee_guids[] = $users[0]->guid;
		continue;
	}

	$new = false;
	$group_invite = groups_invite_get_group_invite($email);
	if (!$group_invite) {
		$new = true;
		$group_invite = groups_invite_create_group_invite($email);
	}

	add_entity_relationship($group_invite->guid, 'invited_by', $inviter->guid);
	add_entity_relationship($group_invite->guid, 'invited_to', $group->guid);

	if (!$new || $resend) {
		$sent = elgg_send_email($site->email, $email, $subject, $message);
		if ($sent) {
			$invited++;
		} else {
			$error++;
		}
	} else {
		$skipped++;
	}
}

foreach ($invitee_guids as $invitee_guid) {
	if (!$invitee_guid) {
		continue;
	}
	$invitee = get_entity($invitee_guid);
	if (!$invitee) {
		$error++;
		continue;
	}

	if (check_entity_relationship($invitee->guid, 'member', $group->guid)) {
		$skipped++;
		continue;
	}

	if ($add) {
		if ($group->canEdit() && groups_join_group($group, $invitee)) {
			$added++;
		} else {
			$error++;
		}
		continue;
	}

	if (check_entity_relationship($group->guid, 'invited', $invitee->guid)) {
		if (!$resend) {
			$skipped++;
			continue;
		}
	}

	add_entity_relationship($group->guid, 'invited', $invitee->guid);

	$url = elgg_normalize_url("groups/invitations/$invitee->username");

	$subject = elgg_echo('groups:invite:subject', array(
		$invitee->name,
		$group->name
			), $invitee->language);

	$body = elgg_echo('groups:invite:body', array(
		$invitee->name,
		$logged_in_user->name,
		$group->name,
		$url,
			), $invitee->language);

	$params = [
		'action' => 'invite',
		'object' => $group,
	];

	$result = notify_user($invitee->getGUID(), $group->owner_guid, $subject, $body, $params);
	if ($result) {
		$invited++;
	} else {
		$error++;
	}
}

$total = $error + $invited + $skipped + $added;
if ($invited) {
	system_message(elgg_echo('groups:invite:result:invited', array($invited, $total)));
}
if ($added) {
	system_message(elgg_echo('groups:invite:result:added', array($added, $total)));
}
if ($skipped) {
	system_message(elgg_echo('groups:invite:result:skipped', array($skipped, $total)));
}
if ($error) {
	register_error(elgg_echo('groups:invite:result:error', array($error, $total)));
}