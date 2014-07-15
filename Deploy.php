<?php

/**
 * Class Deploy
 *
 * Automatically deploy the code using PHP and Git.
 */
class Deploy {
    /**
     * Protect the script from unauthorized access by using a secret access token.
     *
     * @var string
     */
    private $token;

    /**
     * The address of the remote Git repository that contains the code that's being
     * deployed.
     *
     * @var string
     */
    private $remoteRepository;

    /**
     * The branch that's being deployed.
     *
     * Must be present in the remote repository.
     *
     * @var string
     */
    private $branch = 'master';

    /**
     * The location that the code is going to be deployed to.
     *
     * @var string Full path including the trailing slash
     */
    private $targetDir = '/tmp/php-git-deploy/';

    /**
     * Whether to delete the files that are not in the repository but are on the local (server) machine.
     *
     * !!! WARNING !!! This can lead to a serious loss of data if you're not
     * careful. All files that are not in the repository are going to be deleted,
     * except the ones defined in EXCLUDE section.
     * BE CAREFUL!
     *
     * @var bool
     */
    private $deleteFiles = false;

    /**
     * The directories and files that are to be excluded when updating the code.
     *
     * Normally, these are the directories containing files that are not part of
     * code base, for example user uploads or server-specific configuration files.
     * Use rsync exclude pattern syntax for each element.
     *
     * @var array
     */
    private $exclude;

    /**
     * Temporary directory we'll use to stage the code before the update
     *
     * If it already exists, script assumes that it contains an already cloned copy of the
     * repository with the correct remote origin and only fetches changes instead of
     * cloning the entire thing.
     *
     * @var string
     */
    private $tmpDir;

    /**
     * Output the version of the deployed code
     *
     * @var string Full path including the trailing slash
     */
    private $versionFile;

    /**
     * Time limit for each command.
     *
     * @var int Time in seconds
     */
    private $timeLimit = 30;

    /**
     * Backup the target directory into backup directory before deployment.
     *
     * @var bool|string Full backup directory path or false
     */
    private $backupDir = false;

    /**
     * Whether to invoke composer after the repository is cloned or changes are fetched.
     *
     * Composer needs to be available on the server machine, installed globaly
     *
     * @var bool
     * @link http://getcomposer.org/
     */
    private $useComposer = false;

    /**
     * The options that the composer is going to use.
     *
     * @var string Composer options
     * @link http://getcomposer.org/doc/03-cli.md#install
     */
    private $composerOptions = '--no-dev';

    /**
     * Whether to remove the temporary directory after the deployment.
     *
     * It's useful NOT to clean up in order to only fetch changes on the next deployment.
     *
     * @var bool
     */
    private $cleanUp = true;


    /**
     * @param $token
     * @param $remoteRepository
     * @param $targetDir
     */
    public function __construct( $token, $remoteRepository, $targetDir) {
        $this->token            = $token;
        $this->remoteRepository = $remoteRepository;
        $this->targetDir        = $targetDir;
        $this->tmpDir           = '/tmp/spgd-' . md5( $remoteRepository ) . '/';
        $this->versionFile      = 'VERSION';
        $this->exclude          = array( '.git', '.gitignore' );
    }

    public function setExclude($exclude) {
        $this->exclude = array_merge( array( '.git', '.gitignore' ), $exclude );
    }

    /**
     * @param boolean $backupDir
     */
    public function setBackupDir( $backupDir ) {
        $this->backupDir = $backupDir;
    }

    /**
     * @param boolean $cleanUp
     */
    public function setCleanUp( $cleanUp ) {
        $this->cleanUp = $cleanUp;
    }

    /**
     * @param string $tmpDir
     */
    public function setTmpDir( $tmpDir ) {
        $this->tmpDir = $tmpDir;
    }

    /**
     * @param $status
     * @param null $options
     */
    public function setComposer( $status, $options = null ) {
        $this->useComposer = $status;
        if ( $options === null ) {
            $this->composerOptions = $options;
        }
    }

    /**
     * @param $limit
     */
    public function setTimeLimit( $limit ) {
        $this->timeLimit = $limit;
    }

    /**
     * @param $branch
     */
    public function setBranch( $branch ) {
        $this->branch = $branch;
    }

    /**
     * @param $delete
     */
    public function setDeleteFiles( $delete ) {
        $this->deleteFiles = $delete;
    }

    /**
     * @todo find a way for logging to file with log level
     */
    public function setOutput() {
    }

    /**
     * @param $token
     */
    public function start( $token ) {
        if ( $token !== false && $this->token === $token ) {
            try {
                $this->hasRequirements();
                printf(
                    "Environment OK.\nDeploying %s %s\nto\t\t%s ...\n",
                    $this->remoteRepository, $this->branch, $this->targetDir
                );
                $this->getSource();
                if ( $this->versionFile ) {
                    $this->version();
                }
                if ( $this->shouldBackup() ) {
                    $this->backup();
                }
                if ( $this->useComposer ) {
                    $this->composer();
                }
                $this->deployment();
                if ( $this->cleanUp ) {
                    $this->cleanUp();
                }
            } catch ( Exception $e ) {
                if ( $e->getCode() !== 500 ) {
                    if ( $this->cleanUp ) {
                        $this->cleanUp();
                    }
                }
                printf( $e->getMessage() );
            }
        } else {
            printf( 'ACCESS DENIED!' );
        }
    }

    /**
     * Check if the required programs are available
     *
     * @return bool
     * @throws Exception If one of requirement is not available
     */
    private function hasRequirements() {
        $satisfy          = true;
        $requiredBinaries = array( 'git', 'rsync' );
        if ( $this->backupDir !== false ) {
            $requiredBinaries[] = 'tar';
        }
        if ( $this->useComposer === true ) {
            $requiredBinaries[] = 'composer --no-ansi';
        }
        foreach ( $requiredBinaries as $command ) {
            $path = trim( shell_exec( 'which ' . $command ) );
            if ( $path == '' ) {
                throw new Exception(
                    $command . ' not available. It needs to be installed on the server for this script to work.',
                    500
                );
            } else {
                $version = explode( "\n", shell_exec( $command . ' --version' ) );
                printf( "%s %s\n", $path, $version[0] );
            }
        }

        return $satisfy;
    }

    /**
     * @throws Exception
     */
    private function getSource() {
        if ( ! is_dir( $this->tmpDir ) ) {
            // Clone the repository into the TMP_DIR
            $this->execute(
                sprintf(
                    'git clone --depth=1 --branch %s %s %s',
                    $this->branch,
                    $this->remoteRepository,
                    $this->tmpDir
                )
            );
        } else {
            // TMP_DIR exists and hopefully already contains the correct remote origin
            // so we'll fetch the changes and reset the contents.
            $this->execute(
                $commands[] = sprintf(
                    'git --git-dir="%s.git" --work-tree="%s" fetch origin %s',
                    $this->tmpDir,
                    $this->tmpDir,
                    $this->branch
                )
            );
            $this->execute(
                $commands[] = sprintf(
                    'git --git-dir="%s.git" --work-tree="%s" reset --hard FETCH_HEAD',
                    $this->tmpDir,
                    $this->tmpDir
                )
            );
        }
        $this->execute( 'git submodule update --init --recursive' );
    }

    /**
     * @param $command
     *
     * @return bool
     * @throws Exception
     */
    private function execute( $command ) {
        set_time_limit( $this->timeLimit ); // Reset the time limit for each command
        if ( file_exists( $this->tmpDir ) && is_dir( $this->tmpDir ) ) {
            chdir( $this->tmpDir ); // Ensure that we're in the right directory
        }
        $tmp = array();
        exec( $command . ' 2>&1', $tmp, $return_code ); // Execute the command
        printf( "$%s\n%s\n", $command, implode( "\n", $tmp ));
        flush();
        if ( $return_code !== 0 ) {
            throw new Exception(
                'Error encountered! Stopping the script to prevent possible data loss. CHECK THE DATA IN YOUR TARGET DIR!',
                400
            );
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function version() {
        $this->execute(
            sprintf(
                'git --git-dir="%s.git" --work-tree="%s" describe --always > %s',
                $this->tmpDir,
                $this->tmpDir,
                $this->tmpDir . $this->versionFile
            )
        );
    }

    /**
     * @return bool
     */
    private function shouldBackup() {
        return $this->backupDir !== false && is_dir( $this->backupDir );
    }

    /**
     * @throws Exception
     */
    private function backup() {
        $this->execute(
            sprintf(
                'tar czf %s/%s-%s-%s.tar.gz %s*',
                $this->backupDir,
                basename( $this->targetDir ),
                md5( $this->targetDir ),
                date( 'YmdHis' ),
                $this->targetDir // We're backing up this directory into BACKUP_DIR
            )
        );
    }

    /**
     * @throws Exception
     */
    private function composer() {
        $this->execute(
            sprintf(
                'composer --no-ansi --no-interaction --no-progress --working-dir=%s install %s',
                $this->tmpDir,
                $this->composerOptions
            )
        );
    }

    /**
     * @throws Exception
     */
    private function deployment() {
        $exclude = '';
        foreach ( $this->exclude as $exc ) {
            $exclude .= ' --exclude=' . $exc;
        }
        // Deployment command
        $this->execute(
            sprintf(
                'rsync -rltgoDzv %s %s %s %s',
                $this->tmpDir,
                $this->targetDir,
                ( $this->deleteFiles ) ? '--delete-after' : '',
                $exclude
            )
        );
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function cleanUp() {
        return $this->execute( 'rm -rf ' . $this->tmpDir );
    }
} 