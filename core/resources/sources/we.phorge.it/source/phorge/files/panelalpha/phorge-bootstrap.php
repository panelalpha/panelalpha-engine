#!/usr/bin/env php
<?php

/**
 * Creates the first administrator and the password auth provider, from the
 * install stage, before the site is reachable.
 *
 * This exists because of what a fresh Phorge does otherwise.
 * PhabricatorAuthController::isFirstTimeSetup() (line 27) returns true when
 * there are no enabled auth providers AND no user accounts -- and in that state
 * /auth/start/ is an open registration form that makes whoever fills it in an
 * administrator, with `$user->setIsApproved(1)` and an empower transaction
 * applied unconditionally (PhabricatorAuthRegisterController, lines 420-470).
 * There is no token, no invite and no rate limit on it: it is simply the first
 * stranger to load the page. On a public HTTPS domain that is the whole install
 * given away, and it is reachable from the moment Apache binds.
 *
 * Upstream's answer is `bin/auth recover` plus a human at a terminal within
 * seconds of the first boot. There is no unattended equivalent -- `bin/user`
 * has approve, empower and enable but no create, and `bin/auth` has no
 * provider commands at all -- so this script is the unattended equivalent.
 *
 * Idempotent, and deliberately conservative about it: if ANY user account
 * already exists it creates none, because the account is in use and the
 * operator's own administrator is the one that matters.
 *
 * Messages go to stderr. Nothing here needs headers_sent() to stay false, but
 * the deploy log reads both streams and stdout is where `bin/*` puts its own
 * output.
 */

require_once dirname(dirname(__FILE__)).'/scripts/init/init-script.php';

function phorge_say($message) {
  fwrite(STDERR, '[phorge] '.$message."\n");
}

$password_file = idx($argv, 1);
if (!phutil_nonempty_string($password_file) || !is_readable($password_file)) {
  phorge_say('usage: phorge-bootstrap.php <admin-password-file>');
  exit(1);
}

$password = trim(Filesystem::readFile($password_file));
if (!strlen($password)) {
  phorge_say('the administrator password file is empty');
  exit(1);
}

$viewer = PhabricatorUser::getOmnipotentUser();

// ---------------------------------------------------------------------------
// 1. The username/password auth provider.
//
// Phorge ships with no auth providers configured, and a provider is what a
// login form is: PhabricatorAuthStartController renders the forms of the
// enabled providers and nothing else. So without this row the administrator
// created below would exist and have no way to log in -- and, worse,
// isFirstTimeSetup() would still be false only because a user exists, leaving
// a site with a login page that offers nothing.
//
// Written as a plain Lisk insert rather than through
// PhabricatorAuthProviderConfigTransaction, which is what the web UI uses: the
// transaction editor wants an acting user and a content source for an action
// taken before any user exists.
$existing = id(new PhabricatorAuthProviderConfig())->loadAllWhere(
  'providerType = %s AND providerDomain = %s',
  'password',
  'self');

if ($existing) {
  phorge_say('username/password provider already configured');
} else {
  $provider = new PhabricatorPasswordAuthProvider();

  $config = $provider->getDefaultProviderConfig()
    ->setProviderType($provider->getProviderType())
    ->setProviderDomain($provider->getProviderDomain())
    ->setIsEnabled(1)
    ->setShouldAllowLogin(1)
    // The default from getDefaultProviderConfig() is 1, and on a password
    // provider that means the login page carries a "Register New Account"
    // button (PhabricatorPasswordAuthProvider, line 169) -- public self-service
    // signup on a tracker that is on the internet. An administrator who wants
    // it can turn it on in Auth; nothing should turn it on by default.
    ->setShouldAllowRegistration(0)
    // Linking and unlinking are about attaching a second provider to an
    // existing account. There is no second provider here.
    ->setShouldAllowLink(0)
    ->setShouldAllowUnlink(0)
    ->setShouldTrustEmails(0);

  $config->save();
  phorge_say('configured the username/password auth provider');
}

// ---------------------------------------------------------------------------
// 2. The administrator.
//
// Any existing account means this install is in use: a redeploy, a restore, or
// an operator who has already made their own. Creating a second administrator
// with a password from a file would be a back door, not a convenience.
$any_user = id(new PhabricatorUser())->loadOneWhere('1 = 1 LIMIT 1');
if ($any_user) {
  phorge_say(
    pht(
      'account "%s" already exists; not creating an administrator',
      $any_user->getUsername()));
  exit(0);
}

$username = 'admin';

// The address is never delivered to -- metamta.mail-adapter is the test
// adapter, because no daemon is running to drain the queue (see the README) --
// but PhabricatorUserEditor::createNewUser() requires one, and the domain has
// to be a real one or PhabricatorUserEmail::isValidAddress() rejects it.
// APP_URL is the account's own address.
$base_uri = PhabricatorEnv::getEnvConfig('phabricator.base-uri');
$domain = id(new PhutilURI($base_uri))->getDomain();
if (!phutil_nonempty_string($domain)) {
  phorge_say('phabricator.base-uri has no domain; run the setup script first');
  exit(1);
}
$address = $username.'@'.$domain;

$user = id(new PhabricatorUser())
  ->setUsername($username)
  ->setRealName(pht('Administrator'))
  // Otherwise the account lands in the approval queue and the only person who
  // could approve it is itself.
  ->setIsApproved(1);

$email = id(new PhabricatorUserEmail())
  ->setAddress($address)
  // Unverified, Phorge nags on every page and blocks some actions. Nothing can
  // verify it: there is no mail path out of this container.
  ->setIsVerified(1);

id(new PhabricatorUserEditor())
  ->setActor($user)
  ->createNewUser($user, $email);

// Administrator, through the same transaction `bin/user empower` applies
// (PhabricatorPeopleManagementEmpowerWorkflow), so the feed story and the
// account's transaction log look like they should.
$xactions = array();
$xactions[] = $user->getApplicationTransactionTemplate()
  ->setTransactionType(PhabricatorUserEmpowerTransaction::TRANSACTIONTYPE)
  ->setNewValue(true);

id(new PhabricatorUserTransactionEditor())
  ->setActor($viewer)
  ->setActingAsPHID(id(new PhabricatorPeopleApplication())->getPHID())
  ->setContentSource(
    PhabricatorContentSource::newForSource(
      PhabricatorConsoleContentSource::SOURCECONST))
  ->setContinueOnNoEffect(true)
  ->setContinueOnMissingFields(true)
  ->applyTransactions($user, $xactions);

// The password, last: an account that exists without one cannot be logged into,
// which is the safe order to fail in.
$password_object = PhabricatorAuthPassword::initializeNewPassword(
  $user,
  PhabricatorAuthPassword::PASSWORD_TYPE_ACCOUNT);

$password_object
  ->setPassword(new PhutilOpaqueEnvelope($password), $user)
  ->save();

phorge_say(
  pht(
    'created administrator "%s"; the password is in ~/.panelalpha/phorge/admin-password',
    $username));
