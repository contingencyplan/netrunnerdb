<?php


namespace Netrunnerdb\CardsBundle\Services;

use Symfony\Component\HttpFoundation\RequestStack;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

/*
 *
 */
class CardsData
{
	public function __construct(Registry $doctrine, RequestStack $request_stack, Router $router, $dir) {
		$this->doctrine = $doctrine;
        $this->request_stack = $request_stack;
        $this->router = $router;
        $this->dir = $dir;
	}

	public function replaceSymbols($text)
	{
		$text = str_replace('[Subroutine]', '<span class="icon icon-subroutine"></span>', $text);
		$text = str_replace('[Credits]', '<span class="icon icon-credit"></span>', $text);
		$text = str_replace('[Trash]', '<span class="icon icon-trash"></span>', $text);
		$text = str_replace('[Click]', '<span class="icon icon-click"></span>', $text);
		$text = str_replace('[Recurring Credits]', '<span class="icon icon-recurring-credit"></span>', $text);
		$text = str_replace('[Memory Unit]', '<span class="icon icon-mu"></span>', $text);
		$text = str_replace('[Link]', '<span class="icon icon-link"></span>', $text);
		return $text;
	}
	
	public function allsetsnocycledata()
	{
		$list_packs = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Pack')->findBy(array(), array("released" => "ASC", "number" => "ASC"));
		$packs = array();
		$sreal=0; $smax = 0;
		foreach($list_packs as $pack) {
			$real = count($pack->getCards());
			$sreal += $real;
			$max = $pack->getSize();
			$smax += $max;
			$packs[] = array(
					"name" => $pack->getName($this->request_stack->getCurrentRequest()->getLocale()),
					"code" => $pack->getCode(),
					"number" => $pack->getNumber(),
					"cyclenumber" => $pack->getCycle()->getNumber(),
					"available" => $pack->getReleased() ? $pack->getReleased()->format('Y-m-d') : '',
					"known" => intval($real),
					"total" => $max,
					"url" => $this->router->generate('netrunnerdb_netrunner_cards_list', array('pack_code' => $pack->getCode()), true),
			);
		}
		return $packs;
	}

	public function allsetsdata()
	{
		$list_cycles = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Cycle')->findBy(array(), array("number" => "ASC"));
		$cycles = array();
		foreach($list_cycles as $cycle) {
			$packs = array();
			$sreal=0; $smax = 0;
			foreach($cycle->getPacks() as $pack) {
				$real = count($pack->getCards());
				$sreal += $real;
				$max = $pack->getSize();
				$smax += $max;
				$packs[] = array(
						"name" => $pack->getName($this->request_stack->getCurrentRequest()->getLocale()),
						"code" => $pack->getCode(),
				        "cyclenumber" => $cycle->getNumber(),
						"available" => $pack->getReleased() ? $pack->getReleased()->format('Y-m-d') : '',
						"known" => intval($real),
						"total" => $max,
						"url" => $this->router->generate('netrunnerdb_netrunner_cards_list', array('pack_code' => $pack->getCode()), true),
						"search" => "e:".$pack->getCode(),
						"packs" => '',
				);
			}
			if(count($packs) == 1 && $packs[0]["name"] == $cycle->getName($this->request_stack->getCurrentRequest()->getLocale())) {
				$cycles[] = $packs[0];
			}
			else {
				$cycles[] = array(
						"name" => $cycle->getName($this->request_stack->getCurrentRequest()->getLocale()),
						"code" => $cycle->getCode(),
				        "cyclenumber" => $cycle->getNumber(),
						"known" => intval($sreal),
						"total" => $smax,
						"url" => $this->router->generate('netrunnerdb_netrunner_cards_cycle', array('cycle_code' => $cycle->getCode()), true),
						"search" => 'c:'.$cycle->getCode(),
						"packs" => $packs,
				);
			}
		}
		return $cycles;
	}
    
	
	public function get_search_rows($conditions, $sortorder, $forceempty = false)
	{
		$i=0;
		$faction_codes = array(
			'h' => "Haas-Bioroid",
			'w' => "Weyland Consortium",
			'a' => "Anarch",
			's' => "Shaper",
			'c' => "Criminal",
			'j' => "Jinteki",
			'n' => "NBN",
			'-' => "Neutral",
		);
		$side_codes = array(
			'r' => 'Runner',
			'c' => 'Corp',
		);
	
		// construction de la requete sql
		$qb = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Card')->createQueryBuilder('c');
		$qb->leftJoin('c.pack', 'p')
			->leftJoin('p.cycle', 'y')
			->leftJoin('c.type', 't')
			->leftJoin('c.faction', 'f')
			->leftJoin('c.side', 's');
		
		foreach($conditions as $condition)
		{
			$type = array_shift($condition);
			$operator = array_shift($condition);
			switch($type)
			{
				case '': // title or index
					$or = array();
					foreach($condition as $arg) {
						$code = preg_match('/^\d\d\d\d\d$/u', $arg);
						$acronym = preg_match('/^[A-Z]{2,}$/', $arg);
						if($code) {
							$or[] = "(c.code = ?$i)";
							$qb->setParameter($i++, $arg);
						} else if($acronym) {
							$or[] = "(BINARY(c.title) like ?$i)";
							$qb->setParameter($i++, "%$arg%");
							$like = implode('% ', str_split($arg));
							$or[] = "(REPLACE(c.title, '-', ' ') like ?$i)";
							$qb->setParameter($i++, "$like%");
						} else {
							$or[] = "(c.title like ?$i)";
							$qb->setParameter($i++, "%$arg%");
						}
					}
					$qb->andWhere(implode(" or ", $or));
					break;
				case 'x': // text
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.text like ?$i)"; break;
							case '!': $or[] = "(c.text not like ?$i)"; break;
						}
						$qb->setParameter($i++, "%$arg%");
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'a': // flavor
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.flavor like ?$i)"; break;
							case '!': $or[] = "(c.flavor not like ?$i)"; break;
						}
						$qb->setParameter($i++, "%$arg%");
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'e': // extension (pack)
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(p.code = ?$i)"; break;
							case '!': $or[] = "(p.code != ?$i)"; break;
							case '<':
							    if(!isset($qb2)) {
							        $qb2 = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Pack')->createQueryBuilder('p2');
							        $or[] = $qb->expr()->lt('p.released', '(' . $qb2->select('p2.released')->where("p2.code = ?$i")->getDql() . ')');
							    }
							    break;
							case '>':
							    if(!isset($qb3)) {
							        $qb3 = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Pack')->createQueryBuilder('p3');
							        $or[] = $qb->expr()->gt('p.released', '(' . $qb3->select('p3.released')->where("p3.code = ?$i")->getDql() . ')');
							    }
							    break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'c': // cycle (cycle)
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(y.number = ?$i)"; break;
							case '!': $or[] = "(y.number != ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 't': // type
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(t.name = ?$i)"; break;
							case '!': $or[] = "(t.name != ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'f': // faction
					$or = array();
					foreach($condition as $arg) {
						if(array_key_exists($arg, $faction_codes)) {
							switch($operator) {
								case ':': $or[] = "(f.name = ?$i)"; break;
								case '!': $or[] = "(f.name != ?$i)"; break;
							}
							$qb->setParameter($i++, $faction_codes[$arg]);
						}
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 's': // subtype (keywords)
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':':
								$or[] = "((c.keywords = ?$i) or (c.keywords like ?".($i+1).") or (c.keywords like ?".($i+2).") or (c.keywords like ?".($i+3)."))";
								$qb->setParameter($i++, "$arg");
								$qb->setParameter($i++, "$arg %");
								$qb->setParameter($i++, "% $arg");
								$qb->setParameter($i++, "% $arg %");
								break;
							case '!':
								$or[] = "(c.keywords is null or ((c.keywords != ?$i) and (c.keywords not like ?".($i+1).") and (c.keywords not like ?".($i+2).") and (c.keywords not like ?".($i+3).")))";
								$qb->setParameter($i++, "$arg");
								$qb->setParameter($i++, "$arg %");
								$qb->setParameter($i++, "% $arg");
								$qb->setParameter($i++, "% $arg %");
								break;
						}
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'd': // side
					$or = array();
					foreach($condition as $arg) {
						if(array_key_exists($arg, $side_codes)) {
							switch($operator) {
								case ':': $or[] = "(s.name = ?$i)"; break;
								case '!': $or[] = "(s.name != ?$i)"; break;
							}
							$qb->setParameter($i++, $side_codes[$arg]);
						}
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'i': // illustrator
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.illustrator = ?$i)"; break;
							case '!': $or[] = "(c.illustrator != ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'o': // cost or advancementcost
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.cost = ?$i or c.advancementCost = ?$i)"; break;
							case '!': $or[] = "(c.cost != ?$i or c.advancementCost != ?$i)"; break;
							case '<': $or[] = "(c.cost < ?$i or c.advancementCost < ?$i)"; break;
							case '>': $or[] = "(c.cost > ?$i or c.advancementCost > ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'n': // influence
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.factionCost = ?$i)"; break;
							case '!': $or[] = "(c.factionCost != ?$i)"; break;
							case '<': $or[] = "(c.factionCost < ?$i)"; break;
							case '>': $or[] = "(c.factionCost > ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'p': // power (strength) or agendapoints or trashcost
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.strength = ?$i or c.agendaPoints = ?$i or c.trashCost = ?$i)"; break;
							case '!': $or[] = "(c.strength != ?$i or c.agendaPoints != ?$i or c.trashCost != ?$i)"; break;
							case '<': $or[] = "(c.strength < ?$i or c.agendaPoints < ?$i or c.trashCost < ?$i)"; break;
							case '>': $or[] = "(c.strength > ?$i or c.agendaPoints > ?$i or c.trashCost > ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'y': // quantity
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case ':': $or[] = "(c.quantity = ?$i)"; break;
							case '!': $or[] = "(c.quantity != ?$i)"; break;
							case '<': $or[] = "(c.quantity < ?$i)"; break;
							case '>': $or[] = "(c.quantity > ?$i)"; break;
						}
						$qb->setParameter($i++, $arg);
					}
					$qb->andWhere(implode($operator == '!' ? " and " : " or ", $or));
					break;
				case 'r': // release
					$or = array();
					foreach($condition as $arg) {
						switch($operator) {
							case '<': $or[] = "(p.released <= ?$i)"; break;
							case '>': $or[] = "(p.released > ?$i or p.released IS NULL)"; break;
						}
						if($arg == "now") $qb->setParameter($i++, new \DateTime());
						else $qb->setParameter($i++, new \DateTime($arg));
					}
					$qb->andWhere(implode(" or ", $or));
					break;
				case 'u': // unique
					if(($operator == ':' && $condition[0]) || ($operator == '!' && !$condition[0])) {
						$qb->andWhere("(c.uniqueness = 1)");
					} else {
						$qb->andWhere("(c.uniqueness = 0)");
					}
					$i++;
					break;
			}
		}
		
		if(!$i && !$forceempty) {
			return;
		}
		switch($sortorder) {
			case 'set': $qb->orderBy('c.code'); break;
			case 'faction': $qb->orderBy('c.side', 'DESC')->addOrderBy('c.faction')->addOrderBy('c.type'); break;
			case 'type': $qb->orderBy('c.side', 'DESC')->addOrderBy('c.type')->addOrderBy('c.faction'); break;
			case 'cost': $qb->orderBy('c.type')->addOrderBy('c.cost')->addOrderBy('c.advancementCost'); break;
			case 'strength': $qb->orderBy('c.type')->addOrderBy('c.strength')->addOrderBy('c.agendaPoints')->addOrderBy('c.trashCost'); break;
		}
		$qb->addOrderBy('c.title');
		$qb->addOrderBy('c.code');
		$query = $qb->getQuery();
		$rows = $query->getResult();
		
		for($i=0; $i<count($rows); $i++)
		{
			while(isset($rows[$i+1]) && $rows[$i]->getTitle() == $rows[$i+1]->getTitle() && $rows[$i]->getPack()->getCode() == "alt")
			{
				$rows[$i] = $rows[$i+1];
				array_splice($rows, $i+1, 1);
			}
		}
		
		return $rows;
	}

	public function getCardAlternatives($card)
	{
		$qb = $this->doctrine->getRepository('NetrunnerdbCardsBundle:Card')->createQueryBuilder('c');
		$qb->andWhere("c.title = ?1")->setParameter(1, $card->getTitle());
		$qb->andWhere("c.code != ?2")->setParameter(2, $card->getCode());
		$qb->orderBy('c.code');
		$query = $qb->getQuery();
		$rows = $query->getResult();
		$alternatives = array();
		foreach($rows as $alt)
		{
			if($alt->getPack()->getId() == $card->getPack()->getId()) continue;
			$alternatives[] = array(
				"setname" => $alt->getPack()->getName($this->request_stack->getCurrentRequest()->getLocale()),
				"set_code" => $alt->getPack()->getCode(),
				"number" => $alt->getNumber(),
				"code" => $alt->getCode(),
				"url" => $this->router->generate('netrunnerdb_netrunner_cards_zoom', array('card_code' => $alt->getCode()), true),
			);
		}
		return $alternatives;
	}
	
	/**
	 *
	 * @param \Netrunnerdb\CardsBundle\Entity\Card $card
	 * @param string $api
	 * @return multitype:multitype: string number mixed NULL unknown
	 */
	public function getCardInfo($card, $api = false)
	{
	    static $cache = array();
	    static $cacheApi = array();

	    $locale = $this->request_stack->getCurrentRequest()->getLocale();
	    
	    if(!$api && isset($cache[$card->getId()]) && isset($cache[$card->getId()][$locale])) {
	        return $cache[$card->getId()][$locale];
	    }
	    if($api && isset($cacheApi[$card->getId()]) && isset($cacheApi[$card->getId()][$locale])) {
	        return $cacheApi[$card->getId()][$locale];
	    }
	    
		$dbh = $this->doctrine->getConnection();
		
		$cardinfo = array(
				"id" => $card->getId(),
				"last-modified" => $card->getTs()->format('c'),
				"code" => $card->getCode(),
				"title" => $card->getTitle($locale),
				"type" => $card->getType()->getName($locale),
				"type_code" => mb_strtolower($card->getType()->getName()),
				"subtype" => $card->getKeywords($locale),
				"subtype_code" => mb_strtolower($card->getKeywords()),
				"text" => $card->getText($locale),
				"advancementcost" => $card->getAdvancementCost(),
				"agendapoints" => $card->getAgendaPoints(),
				"baselink" => $card->getBaseLink(),
				"cost" => $card->getCost(),
				"faction" => $card->getFaction()->getName($locale),
				"faction_code" => $card->getFaction()->getCode(),
				"faction_letter" => $card->getFaction()->getCode() == 'neutral' ? '-' : substr($card->getFaction()->getCode(), 0, 1),
		        "factioncost" => $card->getFactionCost(),
				"flavor" => $card->getFlavor($locale),
				"illustrator" => $card->getIllustrator(),
				"influencelimit" => $card->getInfluenceLimit(),
				"memoryunits" => $card->getMemoryUnits(),
				"minimumdecksize" => $card->getMinimumDeckSize(),
				"number" => $card->getNumber(),
				"quantity" => $card->getQuantity(),
				"id_set" => $card->getPack()->getId(),
				"setname" => $card->getPack()->getName($locale),
				"set_code" => $card->getPack()->getCode(),
				"side" => $card->getSide()->getName($locale),
				"side_code" => mb_strtolower($card->getSide()->getName()),
				"strength" => $card->getStrength(),
				"trash" => $card->getTrashCost(),
				"uniqueness" => $card->getUniqueness(),
				"limited" => $card->getLimited(),
		        "cyclenumber" => $card->getPack()->getCycle()->getNumber(),
		);

		$cardinfo['url'] = $this->router->generate('netrunnerdb_netrunner_cards_zoom', array('card_code' => $card->getCode(), '_locale' => $locale), true);

		$cardinfo['imagesrc'] = "";
		
		if($locale != 'en' && file_exists($this->dir . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $card->getCode() . ".png"))
		{
			$cardinfo['imagesrc'] = "/web/bundles/netrunnerdbcards/images/cards/$locale/". $card->getCode() . ".png";
		}
		else if(file_exists($this->dir . DIRECTORY_SEPARATOR . "en" . DIRECTORY_SEPARATOR . $card->getCode() . ".png"))
		{
		    $cardinfo['imagesrc'] = "/web/bundles/netrunnerdbcards/images/cards/en/". $card->getCode() . ".png";
		}
		
		if($api) {
			unset($cardinfo['id']);
			unset($cardinfo['id_set']);
			$cardinfo = array_filter($cardinfo, function ($var) { return isset($var); });
			$cacheApi[$card->getId()][$locale] = $cardinfo;
		} else {
			$cardinfo['cssfaction'] = str_replace(" ", "-", mb_strtolower($card->getFaction()->getName()));
			$cache[$card->getId()][$locale] = $cardinfo;
		}
		 
		return $cardinfo;
	}
	
	public function syntax($query)
	{
		// renvoie une liste de conditions (array)
		// chaque condition est un tableau à n>1 éléments
		// le premier est le type de condition (0 ou 1 caractère)
		// les suivants sont les arguments, en OR
		
		$query = preg_replace('/\s+/u', ' ', trim($query));

		$list = array();
		$cond;
		// l'automate a 3 états :
		// 1:recherche de type
		// 2:recherche d'argument principal
		// 3:recherche d'argument supplémentaire
		// 4:erreur de parsing, on recherche la prochaine condition
		// s'il tombe sur un argument alors qu'il est en recherche de type, alors le type est vide
		$etat = 1;
		while($query != "") {
			if($etat == 1) {
				if(isset($cond) && $etat != 4 && count($cond)>2) {
					$list[] = $cond;
				}
				// on commence par rechercher un type de condition
				if(preg_match('/^(\p{L})([:<>!])(.*)/u', $query, $match)) { // jeton "condition:"
					$cond = array(mb_strtolower($match[1]), $match[2]);
					$query = $match[3];
				} else {
					$cond = array("", ":");
				}
				$etat=2;
			} else {
				if( preg_match('/^"([^"]*)"(.*)/u', $query, $match) // jeton "texte libre entre guillements"
				 || preg_match('/^([\p{L}\p{N}\-]+)(.*)/u', $query, $match) // jeton "texte autorisé sans guillements"
				) {
					if(($etat == 2 && count($cond)==2) || $etat == 3) {
						$cond[] = $match[1];
						$query = $match[2];
						$etat = 2;
					} else {
						// erreur
						$query = $match[2];
						$etat = 4;
					}
				} else if( preg_match('/^\|(.*)/u', $query, $match) ) { // jeton "|"
					if(($cond[1] == ':' || $cond[1] == '!') && (($etat == 2 && count($cond)>2) || $etat == 3)) {
						$query = $match[1];
						$etat = 3;
					} else {
						// erreur
						$query = $match[1];
						$etat = 4;
					}
				} else if( preg_match('/^ (.*)/u', $query, $match) ) { // jeton " "
					$query = $match[1];
					$etat=1;
				} else {
					// erreur
					$query = substr($query, 1);
					$etat = 4;
				}
			}
		}
		if(isset($cond) && $etat != 4 && count($cond)>2) {
			$list[] = $cond;
		}
		return $list;
	}
	
	public function validateConditions(&$conditions)
	{
		// suppression des conditions invalides
		$canDoNumeric = array('o', 'n', 'p', 'r', 'y', 'e');
		$numeric = array('<', '>');
		$factions = array('h','w','a','s','c','j','n','-');
		foreach($conditions as $i => $l)
		{
			if(in_array($l[1], $numeric) && !in_array($l[0], $canDoNumeric)) unset($conditions[$i]);
			if($l[0] == 'f')
			{
				$conditions[$i][2] = substr($l[2],0,1);
				if(!in_array($conditions[$i][2], $factions)) unset($conditions[$i]);
			}
		}
	}

	public function buildQueryFromConditions($conditions)
	{
		// reconstruction de la bonne chaine de recherche pour affichage
		return implode(" ", array_map(
				function ($l) {
					return ($l[0] ? $l[0].$l[1] : "")
					. implode("|", array_map(
							function ($s) {
								return preg_match("/^[\p{L}\p{N}\-]+$/u", $s) ?$s : "\"$s\"";
							},
							array_slice($l, 2)
					));
				},
				$conditions
		));
	}
	
	
    
}