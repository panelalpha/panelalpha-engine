<?php
namespace Dsc\Cron;

use Dsc\Cron\Job;
use Dsc\Cron\FileHandler;

/**
 * Represent a crontab
 *
 * @author Benjamin Laugueux <benjamin@yzalis.com>
 */
class Crontab
{
    /**
     * @var FileHandler
     */
    protected $crontabFileHandler;

    /**
     * A collection of jobs
     *
     * @var Job[] $jobs
     */
    private $jobs = array();

    /**
     * The user executing the comment 'crontab'
     *
     * @var string
     */
    protected $user = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Starts empty. Reading `crontab -l` here pulled the calling process's
        // own crontab into every crontab built from this class.
    }

    /**
     * Parse an existing crontab
     *
     * @return Crontab
     *
     * @deprecated Please use {@see FileHandler::parseExistingCrontab()}
     */
    public function parseExistingCrontab()
    {
        $this->getFileHandler()->parseExistingCrontab($this);

        return $this;
    }

    /**
     * Render the crontab and associated jobs
     *
     * @return string
     */
    public function render()
    {
        return implode(PHP_EOL, $this->getJobs());
    }

    /**
     * Write the current crons in the cron table
     *
     * @deprecated Please use {@see FileHandler::write()}
     */
    public function write()
    {
        $this->getFileHandler()->write($this);

        return $this;
    }

    /**
     * Remove all crontab content
     *
     * @return Crontab
     */
    public function flush()
    {
        $this->removeAllJobs();
        $this->getFileHandler()->write($this);
    }

    /**
     * Get unix user to add crontab
     *
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set unix user to add crontab
     *
     * @param string $user
     *
     * @return Crontab
     */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get crontab executable location
     *
     * @return string
     *
     * @deprecated Please use {@see FileHandler::getCrontabExecutable()}
     */
    public function getCrontabExecutable()
    {
        return $this->getFileHandler()->getCrontabExecutable();
    }

    /**
     * Set unix user to add crontab
     *
     * @param string $crontabExecutable
     *
     * @return Crontab
     *
     * @deprecated Please use {@see FileHandler::setCrontabExecutable()}
     */
    public function setCrontabExecutable($crontabExecutable)
    {
        $this->getFileHandler()->setCrontabExecutable($crontabExecutable);

        return $this;
    }

    /**
     * Get all crontab jobs
     *
     * @return Job[] An array of Job
     */
    public function getJobs()
    {
        return $this->jobs;
    }

    /**
     * Get crontab error
     *
     * @return string
     *
     * @deprecated Please use {@see FileHandler::getError()}
     */
    public function getError()
    {
        return $this->getFileHandler()->getError();
    }

    /**
     * Get crontab output
     *
     * @return string
     *
     * @deprecated Please use {@see FileHandler::getOutput()}
     */
    public function getOutput()
    {
        return $this->getFileHandler()->getOutput();
    }

    /**
     * Add a new job to the crontab
     *
     * @param Job $job
     *
     * @return Crontab
     */
    public function addJob(Job $job)
    {
        $this->jobs[$job->getHash()] = $job;

        return $this;
    }

    /**
     * Adda new job to the crontab
     *
     * @param array $jobs
     *
     * @return Crontab
     */
    public function setJobs(array $jobs)
    {
        foreach ($jobs as $job) {
            $this->addJob($job);
        }

        return $this;
    }

    /**
     * Remove all job in the current crontab
     *
     * @return Crontab
     */
    public function removeAllJobs()
    {
        $this->jobs = array();

        return $this;
    }

    /**
     * Remove a specified job in the current crontab
     *
     * @param Job $job
     *
     * @return Crontab
     */
    public function removeJob(Job $job)
    {
        unset($this->jobs[$job->getHash()]);

        return $this;
    }
    
    /**
     * Remove a specified job in the current crontab
     *
     * @param string $hash
     *
     * @return Crontab
     */
    public function removeJobByHash($hash)
    {
        unset($this->jobs[$hash]);
    
        return $this;
    }    

    /**
     * Returns a Crontab File Handler
     *
     * @return FileHandler
     */
    public function getFileHandler()
    {
        if (!$this->crontabFileHandler instanceof FileHandler) {
            $this->crontabFileHandler = new FileHandler();
        }

        return $this->crontabFileHandler;
    }

    /**
     * Set the Crontab File Handler
     *
     * @param FileHandler $command
     *
     * @return $this
     */
    public function setFileHandler(FileHandler $command)
    {
        $this->crontabFileHandler = $command;

        return $this;
    }
    
    /**
     * Remove a specified job in the current crontab
     *
     * @param string $hash
     *
     * @return Crontab
     */
    public function getJobByHash($hash)
    {
        if (!isset($this->jobs[$hash])) 
        {
            throw new \Exception('Invalid Job Hash');
        }
    
        return $this->jobs[$hash];
    }

    /**
     * Enable a specified job in the current crontab
     *
     * @param string $hash
     *
     * @return Crontab
     */
    public function enableJobByHash($hash)
    {
        if (isset($this->jobs[$hash])) {
            $this->jobs[$hash]->setActive(true);
        }
    
        return $this;
    }
    
    /**
     * Disable a specified job in the current crontab
     *
     * @param string $hash
     *
     * @return Crontab
     */
    public function disableJobByHash($hash)
    {
        if (isset($this->jobs[$hash])) {
            $this->jobs[$hash]->setActive(false);
        }
    
        return $this;
    }
}