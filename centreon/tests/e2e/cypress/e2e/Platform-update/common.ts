import { insertFixture } from '../../commons';

const dateBeforeLogin = new Date();

const checkIfSystemUserRoot = (): Cypress.Chainable => {
  return cy
    .exec(`docker exec -i ${Cypress.env('dockerName')} whoami`)
    .then(({ stdout }): Cypress.Chainable<null> | null => {
      const isRoot = stdout === 'root';
      if (isRoot) {
        return null;
      }

      throw new Error(`System user is not root.`);
    });
};

const getCentreonStableMinorVersions = (
  majorVersion: string
): Cypress.Chainable => {
  let commandResult;
  if (Cypress.env('WEB_IMAGE_OS').includes('alma')) {
    commandResult = cy
      .execInContainer({
        command: `bash -e <<EOF
          dnf config-manager --set-disabled 'centreon-*-unstable*' 'centreon-*-testing*' 'mariadb*'
EOF`,
        name: Cypress.env('dockerName')
      })
      .exec(
        `docker exec -i ${Cypress.env(
          'dockerName'
        )} sh -c "dnf --showduplicates list centreon-web | grep centreon-web | grep '${majorVersion}' | awk '{ print \\$2 }' | tr '\n' ' '"`
      );
  } else {
    commandResult = cy
      .execInContainer({
        command: `bash -e <<EOF
          rm -f /etc/apt/sources.list.d/centreon-unstable.list
          rm -f /etc/apt/sources.list.d/centreon-testing.list
          apt-get update
EOF`,
        name: Cypress.env('dockerName')
      })
      .exec(
        `docker exec -i ${Cypress.env(
          'dockerName'
        )} sh -c "apt list -a centreon-web | grep '${majorVersion}' | awk '{ print \\$2 }'"`
      );
  }

  return commandResult.then(({ stdout }): Cypress.Chainable<Array<number>> => {
    cy.log(stdout);
    const stableVersions: Array<number> = [];

    const versionsRegex = /\d+\.\d+\.(\d+)/g;

    [...stdout.matchAll(versionsRegex)].forEach((result) => {
      cy.log(`available version found : ${majorVersion}.${result[1]}`);
      stableVersions.push(Number(result[1]));
    });

    return cy.wrap([...new Set(stableVersions)].sort((a, b) => a - b)); // remove duplicates and order
  });
};

const installCentreon = (version: string): Cypress.Chainable => {
  cy.log(`installing version ${version}...`);

  if (Cypress.env('WEB_IMAGE_OS').includes('alma')) {
    cy.execInContainer({
      command: `bash -e <<EOF
        dnf config-manager --set-disabled 'centreon-*-unstable*' 'centreon-*-testing*' 'mariadb*'
        dnf install -y centreon-web-${version}
        dnf install -y centreon-broker-cbd
        echo 'date.timezone = Europe/Paris' > /etc/php.d/centreon.ini
        /etc/init.d/mysql start
        mkdir -p /run/php-fpm
        systemctl start php-fpm
        systemctl start httpd
        mysql -e "GRANT ALL ON *.* to 'root'@'localhost' IDENTIFIED BY 'centreon' WITH GRANT OPTION"
EOF`,
      name: Cypress.env('dockerName')
    });
  } else {
    cy.execInContainer({
      command: `bash -e <<EOF
        rm -f /etc/apt/sources.list.d/centreon-unstable.list
        rm -f  /etc/apt/sources.list.d/centreon-testing.list
        apt-get update
        apt-get install -y centreon-web-apache=${version}-${Cypress.env(
        'WEB_IMAGE_OS'
      )} centreon-poller=${version}-${Cypress.env('WEB_IMAGE_OS')}
        mkdir /usr/lib/centreon-connector
        echo "date.timezone = Europe/Paris" >> /etc/php/8.1/mods-available/centreon.ini
        sed -i 's#^datadir_set=#datadir_set=1#' /etc/init.d/mysql
        service mysql start
        mkdir -p /run/php
        systemctl start php8.1-fpm
        systemctl start apache2
        mysql -e "GRANT ALL ON *.* to 'root'@'localhost' IDENTIFIED BY 'centreon' WITH GRANT OPTION"
EOF`,
      name: Cypress.env('dockerName')
    });
  }

  cy.intercept({
    method: 'GET',
    url: '/centreon/install/steps/step.php?action=nextStep'
  }).as('nextStep');

  cy.intercept({
    method: 'POST',
    url: '/centreon/install/steps/process/generationCache.php'
  }).as('cacheGeneration');

  // Step 1
  cy.visit('/centreon/install/install.php')
    .get('th.step-wrapper span')
    .contains(1);
  cy.get('#next').click();

  // Step 2
  cy.get('th.step-wrapper span').contains(2);
  cy.wait('@nextStep').get('#next').click();

  // Step 3
  cy.get('th.step-wrapper span').contains(3);
  cy.wait('@nextStep').get('#next').click();

  // Step 4
  cy.get('th.step-wrapper span').contains(4);
  cy.wait('@nextStep').get('#next').click();

  // Step 5
  cy.get('th.step-wrapper span').contains(5);
  cy.get('input[name="admin_password"]').clear();
  cy.get('input[name="admin_password"]').type('Centreon!2021');
  cy.get('input[name="confirm_password"]').clear();
  cy.get('input[name="confirm_password"]').type('Centreon!2021');
  cy.get('input[name="firstname"]').clear();
  cy.get('input[name="firstname"]').type('centreon');
  cy.get('input[name="lastname"]').clear();
  cy.get('input[name="lastname"]').type('centreon');
  cy.get('input[name="email"]').clear();
  cy.get('input[name="email"]').type('centreon@localhost');
  cy.wait('@nextStep').get('#next').click();

  // Step 6
  cy.get('th.step-wrapper span').contains(6);
  cy.get('input[name="root_password"]').clear();
  cy.get('input[name="root_password"]').type('centreon');
  cy.get('input[name="db_password"]').clear();
  cy.get('input[name="db_password"]').type('centreon');
  cy.get('input[name="db_password_confirm"]').clear();
  cy.get('input[name="db_password_confirm"]').type('centreon');
  cy.wait('@nextStep').get('#next').click();

  // Step 7
  cy.get('th.step-wrapper span').contains(7);
  cy.wait('@cacheGeneration', { timeout: 30000 })
    .get('tbody#step_contents span:contains("OK")')
    .should('have.length', 7);
  cy.wait('@nextStep').get('#next').click();

  // Step 8
  cy.get('th.step-wrapper span').contains(8);
  cy.wait('@nextStep').get('#next').click();

  // Step 9
  cy.get('th.step-wrapper span').contains(9);
  cy.wait('@nextStep').get('#finish').click();

  return cy
    .setUserTokenApiV1()
    .applyPollerConfiguration()
    .execInContainer({
      command: `bash -e <<EOF
        systemctl restart cbd
        systemctl restart centengine
        systemctl restart gorgoned
EOF`,
      name: Cypress.env('dockerName')
    });
};

const updatePlatformPackages = (): Cypress.Chainable => {
  return cy
    .copyToContainer({
      destination: '/tmp/packages-update-centreon',
      source: './cypress/fixtures/packages'
    })
    .getWebVersion()
    .then(({ major_version }) => {
      if (Cypress.env('WEB_IMAGE_OS').includes('alma')) {
        return cy.execInContainer({
          command: `bash -e <<EOF
          rm -f /tmp/packages-update-centreon/centreon-${major_version}*.rpm /tmp/packages-update-centreon/centreon-central-${major_version}*.rpm
          dnf install -y /tmp/packages-update-centreon/*.rpm
EOF`,
          name: Cypress.env('dockerName')
        });
      }

      return cy.execInContainer({
        command: `bash -e <<EOF
        rm -f /tmp/packages-update-centreon/centreon_${major_version}*.deb /tmp/packages-update-centreon/centreon-central_${major_version}*.deb
        apt-get update
        apt-get install -y /tmp/packages-update-centreon/centreon-*.deb
EOF`,
        name: Cypress.env('dockerName')
      });
    });
};

const checkPlatformVersion = (platformVersion: string): Cypress.Chainable => {
  const command = Cypress.env('WEB_IMAGE_OS').includes('alma')
    ? `rpm -qa | grep centreon-web | cut -d '-' -f3`
    : `apt -qq list centreon-web | awk '{ print \\$2 }' | cut -d '-' -f1`;

  return cy
    .exec(`docker exec -i ${Cypress.env('dockerName')} sh -c "${command}"`)
    .then(({ stdout }): Cypress.Chainable<null> | null => {
      const isExpected = platformVersion === stdout;
      if (isExpected) {
        return null;
      }

      throw new Error(
        `The platform version is not the correct one (expected: ${platformVersion}, actual: ${stdout}).`
      );
    });
};

const insertResources = (): Cypress.Chainable => {
  const files = [
    'resources/clapi/host1/01-add.json',
    'resources/clapi/service1/01-add.json',
    'resources/clapi/service1/02-set-max-check.json',
    'resources/clapi/service1/03-disable-active-check.json',
    'resources/clapi/service1/04-enable-passive-check.json',
    'resources/clapi/service2/01-add.json',
    'resources/clapi/service2/02-set-max-check.json',
    'resources/clapi/service2/03-disable-active-check.json',
    'resources/clapi/service2/04-enable-passive-check.json',
    'resources/clapi/service3/01-add.json',
    'resources/clapi/service3/02-set-max-check.json',
    'resources/clapi/service3/03-disable-active-check.json',
    'resources/clapi/service3/04-enable-passive-check.json'
  ];

  return cy.wrap(Promise.all(files.map(insertFixture)));
};

export {
  checkIfSystemUserRoot,
  getCentreonStableMinorVersions,
  installCentreon,
  updatePlatformPackages,
  checkPlatformVersion,
  dateBeforeLogin,
  insertResources
};
