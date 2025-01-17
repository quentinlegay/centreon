/* eslint-disable cypress/no-unnecessary-waiting */
import { insertFixture } from '../../commons';

const waitToExport = 10000;
const waitPollerListToLoad = 3000;
const testHostName = 'test_host';

const insertPollerConfigUserAcl = (): Cypress.Chainable => {
  return cy
    .setUserTokenApiV1()
    .executeCommandsViaClapi(
      'resources/clapi/config-ACL/poller-configuration-acl-user.json'
    );
};

const insertHost = (): Cypress.Chainable => {
  return insertFixture('resources/clapi/host1/01-add.json');
};

const getPoller = (pollerName: string): Cypress.Chainable => {
  const query = `SELECT id FROM nagios_server WHERE name = '${pollerName}'`;
  const command = `docker exec -i ${Cypress.env(
    'dockerName'
  )} mysql -ucentreon -pcentreon centreon -e "${query}"`;

  return cy
    .exec(command, { failOnNonZeroExit: true, log: true })
    .then(({ code, stdout, stderr }) => {
      if (!stderr && code === 0) {
        const pollerId = parseInt(stdout.split('\n')[1], 10);

        return cy.wrap(pollerId || '0');
      }

      return cy.log(`Can't execute command on database.`);
    });
};

const removeFixtures = (): Cypress.Chainable => {
  return cy.setUserTokenApiV1().then(() => {
    cy.executeActionViaClapi({
      bodyContent: {
        action: 'DEL',
        object: 'CONTACT',
        values: 'user1'
      }
    });
    cy.executeActionViaClapi({
      bodyContent: {
        action: 'DEL',
        object: 'HOST',
        values: 'test_host'
      }
    });
    cy.executeActionViaClapi({
      bodyContent: {
        action: 'DEL',
        object: 'ACLGROUP',
        values: 'ACL Group test'
      }
    });
    cy.executeActionViaClapi({
      bodyContent: {
        action: 'DEL',
        object: 'ACLMENU',
        values: 'acl_menu_test'
      }
    });
    cy.executeActionViaClapi({
      bodyContent: {
        action: 'DEL',
        object: 'ACLACTION',
        values: 'acl_action_test'
      }
    });
  });
};

const checkIfMethodIsAppliedToPollers = (method: string): void => {
  cy.log('Checking that if the method is applied to pollers');

  let logToSearch = '';
  switch (method) {
    case 'restarted':
      logToSearch = 'Centreon Engine [0-9]*.[0-9]*.[0-9]* starting ...';
      break;
    default:
      logToSearch = 'Reload configuration finished.';
      break;
  }

  cy.wait(waitToExport);

  cy.exec(
    `docker exec -i ${Cypress.env(
      'dockerName'
    )} sh -c "grep '${logToSearch}' /var/log/centreon-engine/centengine.log | tail -1"`
  ).then(({ stdout }): Cypress.Chainable<null> | null => {
    if (stdout) {
      return null;
    }

    throw new Error(`Method has not been applied to pollers`);
  });
};

const clearCentengineLogs = (): Cypress.Chainable => {
  return cy
    .exec(
      `docker exec -i ${Cypress.env(
        'dockerName'
      )} truncate -s 0 /var/log/centreon-engine/centengine.log`
    )
    .exec(
      `docker exec -i ${Cypress.env(
        'dockerName'
      )} truncate -s 0 /etc/centreon-engine/hosts.cfg`
    );
};

const breakSomePollers = (): Cypress.Chainable => {
  return cy.exec(
    `docker exec -i ${Cypress.env(
      'dockerName'
    )} sh -c "chmod a-rwx  /var/cache/centreon/config/engine/1/"`
  );
};

const checkIfConfigurationIsNotExported = (): void => {
  cy.exec(
    `docker exec -i ${Cypress.env(
      'dockerName'
    )} sh -c "grep '${testHostName}' /etc/centreon-engine/hosts.cfg | tail -1"`
  ).then(({ stdout }): Cypress.Chainable<null> | null => {
    if (!stdout) {
      return null;
    }

    throw new Error(`The configuration has been exported`);
  });
};

export {
  insertPollerConfigUserAcl,
  getPoller,
  insertHost,
  removeFixtures,
  checkIfMethodIsAppliedToPollers,
  clearCentengineLogs,
  breakSomePollers,
  waitPollerListToLoad,
  checkIfConfigurationIsNotExported,
  testHostName
};
