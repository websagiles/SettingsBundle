<?php

namespace Mesd\SettingsBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\DialogHelper;
use Mesd\SettingsBundle\Entity\Cluster;
use Mesd\SettingsBundle\Entity\Hive;
use Mesd\SettingsBundle\Model\Definition\SettingDefinition;
use Mesd\SettingsBundle\Model\Setting;
use Mesd\SettingsBundle\Model\SettingManager;
use Mesd\SettingsBundle\Model\SettingValidator;


class ValidateSettingsCommand extends ContainerAwareCommand
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('mesd:setting:setting:validate')
            ->setDescription('Validate settings.')
            ->setDefinition(array(
                new InputOption('forceInsert', null, InputOption::VALUE_NONE, 'Force the insert of new hives, clusters, and/or settings - no user prompt'),
                new InputOption('forceUpdate', null, InputOption::VALUE_NONE, 'Force the update of existing settings - no user prompt'),
                new InputOption('forceDelete', null, InputOption::VALUE_NONE, 'Force the delete of existing settings - no user prompt'),
                new InputOption('forceAll',    null, InputOption::VALUE_NONE, 'Force insert, update, and delete of settings - no user prompt'),
              ))
            ->setHelp(<<<EOT
The <info>mesd:setting:setting:validate</info> command validates all settings
in the database, in relation to the setting definition(s).

The validate process will prompt the user for confirmation on any required
changes to settings in the database.

There are three types of changes that could be required:

  Insert - New hives, clusters, or settings that have been defined, but
           don't exist in database. Inserts should not be destructive to
           existing data.

  Update - Changes to the setting definition that need to be applied to
           settings in the database. Updates can potentially be destructive
           to existing data. i.e. Format change where value is no longer
           compatible.

  Delete - Removed nodes from setting definition that need to be purged from
           the settings in the database. Deletes are always destructive to
           existing data.

Force inserts without prompting for confirmation with the <comment>--forceInsert</comment> option:

<info>php app/console mesd:setting:setting:validate --forceInsert</info>

Force updates without prompting for confirmation with the <comment>--forceUpdate</comment> option:

<info>php app/console mesd:setting:setting:validate --forceUpdate</info>

Force deletes without prompting for confirmation with the <comment>--forceDelete</comment> option:

<info>php app/console mesd:setting:setting:validate --forceDelete</info>

Force all modifications without prompting for confirmation with the <comment>--forceAll</comment> option:

<info>php app/console mesd:setting:setting:validate --forceAll</info>

EOT
            );
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get user options
        $confirmation = array(
            'forceInsert' => $input->getOption('forceInsert'),
            'forceUpdate' => $input->getOption('forceUpdate'),
            'forceDelete' => $input->getOption('forceDelete'),
            'forceAll'    => $input->getOption('forceAll')
        );

        // Get needed services
        $settingManager    = $this->getContainer()->get("mesd_settings.setting_manager");
        $definitionManager = $this->getContainer()->get("mesd_settings.definition_manager");
        $entityManager     = $this->getContainer()->get("doctrine.orm.entity_manager");

        // Get Dialog Helper
        $dialog = $this->getHelper('dialog');

        // Load all definition files
        $settingDefinitions = $definitionManager->loadFiles();

        // Ensure all hives and clusters are defined in the database
        foreach ($settingDefinitions as $key => $settingDefinition) {
            $this->validateDefinition($settingDefinition, $settingManager, $input, $output, $dialog, $confirmation);
        }

        // Load hive collection
        $hiveCollection = $entityManager
            ->getRepository('MesdSettingsBundle:Hive')
            ->findAll();

        // Loop through all hives validating as we go
        foreach ($hiveCollection as $hiveKey => $hive) {

            // If settings are defined at hive, clusters
            // will use the same SettingDefinition.
            if ($hive->getDefinedAtHive()) {

                $output->writeln(array(
                    '',
                    sprintf(
                        '<info>Checking hive: %s - Settings defined at hive</info>',
                        $hive->getName()
                    ),
                    ''
                ));

                // Load hive's SettingDefinition
                $settingDefinition = $definitionManager
                    ->loadFileByHiveAndCluster($hive);

                // Load Hive's cluster collection
                $clusterCollection = $hive->getCluster();

                // Loop through clusters
                foreach ($clusterCollection as $clusterKey => $cluster) {
                    $cluster = $this->validateCluster($cluster, $settingDefinition, $input, $output, $dialog, $confirmation);
                    $entityManager->persist($cluster);
                }
            }

            // Settings are defined at cluster, each using
            // their own SettingDefinition.
            else {

                $output->writeln(array(
                    '',
                    sprintf(
                        '<info>Checking hive: %s - Settings defined at cluster</info>',
                        $hive->getName()
                    ),
                    ''
                ));

                // Load Hive's cluster collection
                $clusterCollection = $hive->getCluster();

                // Loop through clusters
                foreach ($clusterCollection as $clusterKey => $cluster) {

                    // Load clusters's SettingDefinition
                    $settingDefinition = $definitionManager
                        ->loadFileByHiveAndCluster($hive, $cluster);

                    $cluster = $this->validateCluster($cluster, $settingDefinition, $input, $output, $dialog, $confirmation);
                    $entityManager->persist($cluster);
                }

            }

            $entityManager->flush();

        }


        $output->writeln(array(
            '',
            '<info>Setting validation complete!</info>',
            ''
        ));
    }



    /**
     * Validate setting definition exists in database
     *
     * @param SettingDefinition
     * @param InputInterface
     * @param OutputInterface
     * @param DialogHelper
     * @param array $confirmation
     */
    protected function validateDefinition(
        SettingDefinition $settingDefinition,
        SettingManager $settingManager,
        InputInterface $input,
        OutputInterface $output,
        DialogHelper $dialog,
        $confirmation
    )
    {
        // Ensure Hive exists in database
        if (!$settingManager->hiveExists($settingDefinition->getHiveName())) {

            $output->writeln(array(
                sprintf(
                    "<comment>Hive '%s' is missing from the database</comment>",
                    $settingDefinition->getHiveName()
                ),
                ''
            ));

            // Did the user request the 'force' option ?
            if (!$confirmation['forceInsert'] && !$confirmation['forceAll']) {
                $confirmInsert = $dialog->askConfirmation(
                    $output,
                    sprintf("Would you like to insert the hive? (y/n): "),
                    false
                );
                $output->writeln('');
            }
            else {
                $confirmInsert = true;
            }

            // Insert hive, if confirmed
            if (true === $confirmInsert) {
                $definedAtHive = ('cluster' === $settingDefinition->getType() ? false : true);
                $settingManager->createHive(
                    $settingDefinition->getHiveName(),
                    null,
                    $definedAtHive
                );

                $output->writeln(array(
                    sprintf(
                        "<info>Hive '%s' has been inserted!</info>",
                        $settingDefinition->getHiveName()
                    ),
                    ''
                ));
            }
        }


        // If type is cluster, ensure cluster exisits in database
        $cluster = $settingManager->clusterExists(
            $settingDefinition->getHiveName(),
            $settingDefinition->getKey()
        );
        if ('cluster' === $settingDefinition->getType() && !$cluster) {
            
            $output->writeln(array(
                sprintf(
                    "<comment>Cluster '%s' is missing from the database</comment>",
                    $settingDefinition->getKey()
                ),
                ''
            ));

            // Did the user request the 'force' option ?
            if (!$confirmation['forceInsert'] && !$confirmation['forceAll']) {
                $confirmInsert = $dialog->askConfirmation(
                    $output,
                    sprintf("Would you like to insert the cluster? (y/n): "),
                    false
                );
                $output->writeln('');
            }
            else {
                $confirmInsert = true;
            }

            // Insert cluster, if confirmed
            if (true === $confirmInsert) {
                $settingManager->createCluster(
                    $settingDefinition->getHiveName(),
                    $settingDefinition->getKey()
                );

                $output->writeln(array(
                    sprintf(
                        "<info>Cluster '%s' has been inserted!</info>",
                        $settingDefinition->getKey()
                    ),
                    ''
                ));
            }
        }

    }



    /**
     * Validate a cluster against a setting definition
     *
     * @param Cluster
     * @param SettingDefinition
     * @param InputInterface
     * @param OutputInterface
     * @param DialogHelper
     * @param array $confirmation
     * @return Cluster
     */
    protected function validateCluster(
        Cluster $cluster,
        SettingDefinition $settingDefinition,
        InputInterface $input,
        OutputInterface $output,
        DialogHelper $dialog,
        $confirmation
    )
    {

        // Create array of current cluster settings to
        // check against, and to track what has not been
        // verified by the final stage (Settings to Delete).
        $clusterSettings = $cluster->getSettingArray();

        if(!is_array($clusterSettings)) {
            $clusterSettings = array();
        }

        // First check each setting node in definition
        // and determine existence/compliance within cluster
        foreach ($settingDefinition->getSettingNodes() as $settingKey => $settingNode ) {


            // INSERT Operation - Check for existence in cluster
            if(!array_key_exists($settingKey, $clusterSettings)) {

                 $output->writeln(array(
                    sprintf(
                        "<comment>Cluster '%s' is missing setting '%s':</comment>",
                        $cluster->getName(),
                        $settingKey
                    ),
                    ''
                ));

                // Did the user request the 'force' option ?
                if (!$confirmation['forceInsert'] && !$confirmation['forceAll']) {
                    $confirmInsert = $dialog->askConfirmation(
                        $output,
                        sprintf("Would you like to insert the setting? (y/n): "),
                        false
                    );
                    $output->writeln('');
                }
                else {
                    $confirmInsert = true;
                }

                // Insert into cluster, if confirmed
                if (true === $confirmInsert) {
                    $newSetting = new Setting();
                    $newSetting->setName($settingKey);
                    $newSetting->setValue($settingNode->getDefault());
                    $newSetting->setCluster($cluster);
                    $cluster->addSetting($newSetting);

                    $output->writeln(array(
                        sprintf(
                            "<info>Cluster '%s' setting '%s' has been inserted!</info>",
                            $cluster->getName(),
                            $settingKey
                        ),
                        ''
                    ));
                }
            }


            // UPDATE Operation - Check for definition compliance in cluster
            else {

                // Validate existing cluster setting
                $settingValidator = new SettingValidator(
                    $settingNode,
                    $cluster->getSetting($settingKey)
                );

                $validationResults = $settingValidator->validate();

                // If invalid, alert user
                if (!$validationResults['valid']) {

                    $output->writeln(array(
                        sprintf(
                            "<comment>Cluster '%s' has an invalid setting '%s':</comment>",
                            $cluster->getName(),
                            $settingKey
                        ),
                        '<comment>'.$validationResults['validationMessage'].'</comment>'
                    ));

                    // Did the user request the 'force' option ?
                    if (!$confirmation['forceUpdate'] && !$confirmation['forceAll']) {
                        $confirmUpdate = $dialog->askConfirmation(
                            $output,
                            sprintf(
                                "Would you like to update the setting? This can be destructive to the setting value. (y/n): ",
                                $cluster->getName(),
                                $settingKey
                            ),
                            false
                        );
                        $output->writeln('');
                    }
                    else {
                        $confirmUpdate = true;
                    }

                    // Update cluster setting, if confirmed
                    if (true === $confirmUpdate) {
                        $setting = $settingValidator->sanitize();
                        $cluster->addSetting($setting);

                        $output->writeln(array(
                            sprintf(
                                "<info>Cluster '%s' setting '%s' has been updated!</info>",
                                $cluster->getName(),
                                $settingKey
                            ),
                            ''
                        ));
                    }

                }

                // Remove setting from clusterSettings
                // tracking array
                unset($clusterSettings[$settingKey]);
            }
        }


        // DELETE Operation - Check for cluster settings to remove
        if (0 < count($clusterSettings)) {
            foreach ($clusterSettings as $settingKey => $setting) {

                $output->writeln(array(
                    sprintf(
                        "<comment>Cluster '%s' has an invalid setting '%s' which does not exist in the definition</comment>",
                        $cluster->getName(),
                        $settingKey
                    ),
                    ''
                ));

                // Did the user request the 'force' option ?
                if (!$confirmation['forceDelete'] && !$confirmation['forceAll']) {
                    $confirmDelete = $dialog->askConfirmation(
                        $output,
                        sprintf(
                            "Would you like to delete the setting from the cluster? This is destructive to the setting value. (y/n): ",
                            $cluster->getName(),
                            $settingKey
                        ),
                        false
                    );
                    $output->writeln('');
                }
                else {
                    $confirmDelete = true;
                }

                // Update cluster setting, if confirmed
                if (true === $confirmDelete) {
                    $cluster->removeSetting(
                        $cluster->getSetting($settingKey)
                    );

                    $output->writeln(array(
                        sprintf(
                            "<info>Cluster '%s' setting '%s' has been deleted!</info>",
                            $cluster->getName(),
                            $settingKey
                        ),
                        ''
                    ));
                }

            }
        }

        // Persist updated cluster
        $entityManager = $this->getContainer()->get("doctrine.orm.entity_manager");
        $entityManager->persist($cluster);
        $entityManager->flush();

        return $cluster;
    }

}