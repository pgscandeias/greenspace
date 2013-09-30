<?php
// src/Acme/DemoBundle/Command/GreetCommand.php
namespace My\MainBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use My\MainBundle\Entity\Base;
use My\MainBundle\Entity\Game;
use My\MainBundle\Entity\Map;
use My\MainBundle\Entity\Fleet;


class GameFleetTickerCommand extends ContainerAwareCommand
{
    protected $em; // EntityManager
    protected $input;
    protected $output;
    protected $game;

    const TURN_SLEEP = 1; // In seconds


    protected function configure()
    {
        $this
            ->setName('game:ticker:fleet')
            ->setDescription('Governs fleet movement and combat')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Time between turn updates, in seconds')
            ->addOption('game', null, InputOption::VALUE_REQUIRED, 'Game Id')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->em = $this->getContainer()->get('doctrine')->getManager();
        $this->input = $input;
        $this->output = $output;

        $turnSleep = $this->input->getOption('sleep') ?: static::TURN_SLEEP;

        $this->game = $this->getRepository('Game')->find($this->input->getOption('game'));
        if (!$this->game) { throw new \Exception('Invalid game'); }

        $turn = 0;
        while (1) {
            $startTime = microtime(true);
            $turn++;
            
            $this
                ->fleetMovement()
                ->fleetCombat()
                ->baseConquest()
            ;

            $output->writeln("Turn #" . $turn . " (".(microtime(true) - $startTime)."s)");
            sleep($turnSleep);
        }
    }

    // All moving fleets creep towards their destinations
    protected function fleetMovement()
    {
        $fleets = $this->getRepository('Fleet')->getMoving($this->game);

        foreach ($fleets as $fleet) {
            $fleet
                ->clearBase() // Leave origin
                ->move(Fleet::DEFAULT_SPEED)
            ;

            if ($fleet->hasArrived()) {
                $fleet
                    ->setBase($fleet->getDestination())
                    ->clearOrigin()
                    ->clearDestination()
                    ->setDistance(null)
                ;
            }

            $this->em->persist($fleet);
        }

        $this->em->flush();

        return $this;
    }

    // Non-aligned fleets occupying the same base have to fight
    protected function fleetCombat()
    {
        return $this;
    }

    // Fleets conquer the bases they're occupying
    protected function baseConquest()
    {
        $fleets = $this->getRepository('Fleet')->getOverHostileBases($this->game);
        $bases = [];

        foreach ($fleets as $fleet) {
            $base = $fleet->getBase();
            $bases[$base->getId()] = $base;
        }

        foreach ($bases as $base) {
            $conquered = true;
            $player = null;

            foreach ($base->getFleets() as $fleet) {
                $player = $player ?: $fleet->getPlayer();
                // If another player has a fleet, base cannot be conquered
                if ($player != $fleet->getPlayer())
                    $conquered = false;
            }

            if ($conquered) {
                $base->setPlayer($player);
                $this->em->persist($base);
            }
        }

        $this->em->flush();

        return $this;
    }


    protected function getRepository($entity)
    {
        return $this->getContainer()
            ->get('doctrine')
            ->getRepository('MyMainBundle:'.ucfirst($entity))
        ;
    }
}