<?php

namespace Biopen\CoreBundle\Command;

use Biopen\SaasBundle\Command\GoGoAbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Biopen\CoreBundle\Document\MigrationState;
use Biopen\CoreBundle\Document\GoGoLogUpdate;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;
/**
 * Command to update database when schema need migration
 * Also provide some update message in the admin dashboard
 */
class MigrationCommand extends GoGoAbstractCommand
{
    // -----------------------------------------------------------------
    // DO NOT REMOVE A SINGLE ELEMENT OF THOSE ARRAYS, ONLY ADD NEW ONES
    // -----------------------------------------------------------------
    public $migrations = [
      // March 2019
      // "db.Category.renameCollection('CategoryGroup')",
      // "db.Option.renameCollection('Category')"
    ];

    public $commands = [
      // v2.3.1
      "app:elements:updateJson all",
      // v2.3.4
      "app:elements:updateJson all"
    ];

    public $messages = [
        // v2.3.0
        "Un champ <b>Image (url)</b> est maintenant disponible dans la confiugration du formulaire !",
        "Vous pouvez désormais customizer la popup qui s'affiche au survol d'un marqueur. Allez dans Personnalisation -> Marqueur / Popup",
        "Nouvelle option pour le menu (Personnalisation -> La Carte -> onglet Menu) : afficher à côté de chaque catégories le nombre d'élements disponible pour cette catégorie",
        // v2.3.1
        "Vous pouvez maintenant renseigner la licence qui protège vos données dans Personnalisation -> Configuration Générale",
        // v2.3.4
        "Amélioration du <b>système d'import</b>: vous pouvez maintenant faire correspondre les champs et les catégories avant d'importer. Des vidéos tutoriels ont été réalisés. <u>Merci de parcourir vos imports dynamiques pour les mettre à jour avec le nouveau système</u>",
        "<b>La gestion des permissions des utilisateurs fait peau neuve !</b> <u>Votre ancienne configuration ne sera peut être plus valide</u>. Veuillez vous rendre dans le <b>menu Utilisateurs pour mettre à jour les roles des utilisateurs et des groupes</b> d'utilisateurs.",
        "Vous pouvez maintenant configurer des mot clés à exclure dans la recherche des éléments. Rendez-vous dans Personnalisation -> La Carte -> Onglet Recherche"
    ];


    protected function gogoConfigure()
    {
        $this->setName('db:migrate')
             ->setDescription('Update datatabse each time after code update');
    }

    protected function gogoExecute($em, InputInterface $input, OutputInterface $output)
    {
        $migrationState = $em->createQueryBuilder('BiopenCoreBundle:MigrationState')->getQuery()->getSingleResult();
        if ($migrationState == null) // Meaning the migration state was not yet in the place in the code
        {
            $migrationState = new MigrationState();
            $em->persist($migrationState);
        }

        try {
            // Collecting the Database to be updated
            $dbs = ['gogocarto_default'];
            $dbNames = $em->createQueryBuilder('BiopenSaasBundle:Project')->select('domainName')->hydrate(false)->getQuery()->execute()->toArray();
            foreach ($dbNames as $object) { $dbs[] = $object['domainName']; }

            if (count($this->migrations) > $migrationState->getMigrationIndex()) {
                $migrationsToRun = array_slice($this->migrations, $migrationState->getMigrationIndex());
                foreach($dbs as $db) {
                    foreach($migrationsToRun as $migration) {
                        $this->runCommand($db, $migration);
                    }
                }
                $this->log(count($migrationsToRun) . " migrations performed");
            } else {
                $this->log("No Migrations to perform");
            }

            $asyncService = $this->getContainer()->get('biopen.async');
            // run them syncronously otherwise all the command will be run at once
            $asyncService->setRunSynchronously(true);
            if (count($this->commands) > $migrationState->getCommandsIndex()) {
                $commandsToRun = array_slice($this->commands, $migrationState->getCommandsIndex());
                $this->log(count($commandsToRun) . " commands to run");
                foreach($dbs as $db) {
                    foreach($commandsToRun as $command) {
                        $this->log("call command" . $command . " on project " . $db);
                        $asyncService->callCommand($command, [], $db);
                    }
                }
            } else {
                $this->log("No commands to run");
            }

            if (count($this->messages) > $migrationState->getMessagesIndex()) {
                $messagesToAdd = array_slice($this->messages, $migrationState->getMessagesIndex());
                $this->log(count($messagesToAdd) . " messages to add");
                foreach($dbs as $db) {
                    $this->log("add message on project " . $db);
                    foreach($messagesToAdd as $message) {
                        // create a GoGoLogUpdate
                        $asyncService->callCommand('gogolog:add:message', ['"' . $message . '"'], $db);
                    }
                }
                $this->log(count($messagesToAdd) . " messages added to admin dashboard");
            } else {
                $this->log("No Messages to add to dashboard");
            }
        }
        catch (\Exception $e) {
            $message = $e->getMessage() . '</br>' . $e->getFile() . ' LINE ' . $e->getLine();
            $this->error("Error performing migrations: " . $message);
        }

        $migrationState->setMigrationIndex(count($this->migrations));
        $migrationState->setCommandsIndex(count($this->commands));
        $migrationState->setMessagesIndex(count($this->messages));
        $em->flush();
    }

    private function runCommand($db, $command)
    {
        $process = new Process("mongo {$db} --eval \"{$command}\"");
        return $process->run();
    }
}