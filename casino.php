<?php
declare(strict_types=1);
session_start();

/**
 * Casino Single-Page Engine (PHP) — Lottery + Blackjack + Baccarat
 * - Single file for cPanel/File Manager
 * - AJAX API built into same file (POST JSON)
 * - Session-based state + history per game
 * - Tabs switch games seamlessly; histories persist while you play
 *
 * Install:
 *   1) Save as public_html/casino.php
 *   2) Visit https://yourdomain.com/casino.php
 */

const MIN_N = 1;
const MAX_N = 10;
const LOTTO_SLOTS = 5;

const LOTTO_ENABLE_BIAS = true;
const LOTTO_BIAS_FACTOR = 1.15; // 1.0 = pure random; 1.05–1.25 modest bias

const LOTTO_MAX_HISTORY = 25;
const BJ_MAX_HISTORY = 25;
const BAC_MAX_HISTORY = 25;

const API_OK = 200;
const API_BAD = 400;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function nowIso(): string { return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'); }

function json_out(int $code, array $payload): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

function ensure_csrf(): void {
  if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
}
ensure_csrf();

function require_csrf(?string $token): void {
  if (!$token || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    json_out(403, ['ok' => false, 'error' => 'Invalid CSRF token. Refresh the page.']);
  }
}

function init_state(): void {
  if (!isset($_SESSION['casino'])) {
    $_SESSION['casino'] = [
      'lotto' => [
        'spins' => 0,
        'wins' => 0,
        'bestTier' => null,
        'bestExact' => 0,
        'streakWin' => 0,
        'streakLose' => 0,
        'history' => [],
      ],
      'bj' => [
        'handId' => null,
        'phase' => 'idle', // idle | player | dealer | complete
        'deck' => [],
        'player' => [],
        'dealer' => [],
        'result' => null,
        'history' => [],
      ],
      'bac' => [
        'handId' => null,
        'phase' => 'idle', // idle | complete
        'deck' => [],
        'player' => [],
        'banker' => [],
        'result' => null,
        'history' => [],
      ],
    ];
  }
}
init_state();

/* -----------------------------
   LOTTERY ENGINE
------------------------------*/

function lotto_tiers(): array {
  return [
    [
      "key" => "JACKPOT",
      "label" => "🏆 JACKPOT",
      "desc" => "All 5 match in the correct slot positions.",
      "condition" => fn(array $m) => $m["exact"] === LOTTO_SLOTS,
      "style" => "tier-jackpot"
    ],
    [
      "key" => "TIER_1",
      "label" => "💎 Tier 1",
      "desc" => "4 exact slot matches.",
      "condition" => fn(array $m) => $m["exact"] === 4,
      "style" => "tier-1"
    ],
    [
      "key" => "TIER_2",
      "label" => "✨ Tier 2",
      "desc" => "3 exact slot matches OR 5 any-order matches (but not all exact).",
      "condition" => fn(array $m) => $m["exact"] === 3 || ($m["anyOrder"] === LOTTO_SLOTS && $m["exact"] < LOTTO_SLOTS),
      "style" => "tier-2"
    ],
    [
      "key" => "TIER_3",
      "label" => "🎯 Tier 3",
      "desc" => "2 exact slot matches OR 4 any-order matches.",
      "condition" => fn(array $m) => $m["exact"] === 2 || $m["anyOrder"] === 4,
      "style" => "tier-3"
    ],
    [
      "key" => "CONSOLATION",
      "label" => "🍀 Consolation",
      "desc" => "1+ exact match OR 3+ any-order matches.",
      "condition" => fn(array $m) => $m["exact"] >= 1 || $m["anyOrder"] >= 3,
      "style" => "tier-cons"
    ],
    [
      "key" => "LOSS",
      "label" => "❌ Loss",
      "desc" => "No meaningful matches this spin.",
      "condition" => fn(array $m) => true,
      "style" => "tier-loss"
    ],
  ];
}

function clamp_int($value, int $min, int $max): ?int {
  $n = filter_var($value, FILTER_VALIDATE_INT);
  if ($n === false) return null;
  if ($n < $min || $n > $max) return null;
  return $n;
}

function lotto_count_any_order(array $picks, array $draw): int {
  $pCount = array_count_values($picks);
  $dCount = array_count_values($draw);
  $sum = 0;
  foreach ($pCount as $num => $pc) {
    $dc = $dCount[$num] ?? 0;
    $sum += min($pc, $dc);
  }
  return $sum;
}

function lotto_count_exact(array $picks, array $draw): int {
  $exact = 0;
  for ($i = 0; $i < LOTTO_SLOTS; $i++) {
    if ($picks[$i] === $draw[$i]) $exact++;
  }
  return $exact;
}

function lotto_exact_mask(array $picks, array $draw): array {
  $mask = [];
  for ($i = 0; $i < LOTTO_SLOTS; $i++) {
    $mask[$i] = ($picks[$i] === $draw[$i]);
  }
  return $mask;
}

function lotto_draw_pure(): array {
  $draw = [];
  for ($i = 0; $i < LOTTO_SLOTS; $i++) {
    $draw[] = random_int(MIN_N, MAX_N);
  }
  return $draw;
}

function lotto_draw_biased(array $picks): array {
  if (!LOTTO_ENABLE_BIAS || LOTTO_BIAS_FACTOR <= 1.0) return lotto_draw_pure();

  $draw = [];
  for ($i = 0; $i < LOTTO_SLOTS; $i++) {
    $pick = $picks[$i];
    $avoidChance = min(0.85, 0.55 * LOTTO_BIAS_FACTOR);
    $r = mt_rand() / mt_getrandmax();
    if ($r < $avoidChance) {
      do { $n = random_int(MIN_N, MAX_N); } while ($n === $pick);
      $draw[] = $n;
    } else {
      $draw[] = random_int(MIN_N, MAX_N);
    }
  }
  return $draw;
}

function lotto_evaluate(array $picks, array $draw): array {
  $exact = lotto_count_exact($picks, $draw);
  $anyOrder = lotto_count_any_order($picks, $draw);
  $mask = lotto_exact_mask($picks, $draw);

  $near = 0;
  for ($i = 0; $i < LOTTO_SLOTS; $i++) {
    if ($picks[$i] !== $draw[$i] && abs($picks[$i] - $draw[$i]) === 1) $near++;
  }

  return [
    'exact' => $exact,
    'anyOrder' => $anyOrder,
    'near' => $near,
    'mask' => $mask,
  ];
}

function lotto_classify(array $metrics): array {
  foreach (lotto_tiers() as $tier) {
    $cond = $tier['condition'];
    if ($cond($metrics)) return $tier;
  }
  return ['key'=>'LOSS','label'=>'❌ Loss','desc'=>'No meaningful matches.','style'=>'tier-loss'];
}

function lotto_record(array $picks, array $draw, array $metrics, array $tier): void {
  $lotto = &$_SESSION['casino']['lotto'];
  $lotto['spins']++;

  $isWin = $tier['key'] !== 'LOSS';
  if ($isWin) {
    $lotto['wins']++;
    $lotto['streakWin']++;
    $lotto['streakLose'] = 0;
  } else {
    $lotto['streakLose']++;
    $lotto['streakWin'] = 0;
  }

  if ($metrics['exact'] > ($lotto['bestExact'] ?? 0)) $lotto['bestExact'] = $metrics['exact'];

  $order = array_column(lotto_tiers(), 'key');
  $currentBest = $lotto['bestTier'];
  if ($currentBest === null) {
    $lotto['bestTier'] = $tier['key'];
  } else {
    $curIdx = array_search($currentBest, $order, true);
    $newIdx = array_search($tier['key'], $order, true);
    if ($newIdx !== false && $curIdx !== false && $newIdx < $curIdx) $lotto['bestTier'] = $tier['key'];
  }

  array_unshift($lotto['history'], [
    'time' => nowIso(),
    'tierKey' => $tier['key'],
    'tierLabel' => $tier['label'],
    'tierDesc' => $tier['desc'],
    'tierStyle' => $tier['style'],
    'picks' => $picks,
    'draw' => $draw,
    'metrics' => $metrics,
  ]);

  $lotto['history'] = array_slice($lotto['history'], 0, LOTTO_MAX_HISTORY);
}

function lotto_stats_payload(): array {
  $lotto = $_SESSION['casino']['lotto'];
  $spins = (int)$lotto['spins'];
  $wins  = (int)$lotto['wins'];
  $winRate = $spins > 0 ? round(($wins / $spins) * 100, 1) : 0.0;

  $bestTierLabel = null;
  if (!empty($lotto['bestTier'])) {
    foreach (lotto_tiers() as $t) {
      if ($t['key'] === $lotto['bestTier']) { $bestTierLabel = $t['label']; break; }
    }
  }

  return [
    'spins' => $spins,
    'wins' => $wins,
    'winRate' => $winRate,
    'bestTierLabel' => $bestTierLabel,
    'bestExact' => (int)($lotto['bestExact'] ?? 0),
    'streakWin' => (int)($lotto['streakWin'] ?? 0),
    'streakLose' => (int)($lotto['streakLose'] ?? 0),
    'history' => $lotto['history'],
  ];
}

/* -----------------------------
   CARD UTILITIES (Blackjack/Baccarat)
------------------------------*/

function card_suits(): array { return ['♠','♥','♦','♣']; }
function card_ranks(): array { return ['A','2','3','4','5','6','7','8','9','10','J','Q','K']; }

function build_deck(int $decks = 1): array {
  $deck = [];
  for ($d = 0; $d < $decks; $d++) {
    foreach (card_suits() as $s) {
      foreach (card_ranks() as $r) {
        $deck[] = ['r' => $r, 's' => $s];
      }
    }
  }
  shuffle($deck);
  return $deck;
}

function draw_card(array &$deck): array {
  if (count($deck) === 0) $deck = build_deck(1);
  return array_pop($deck);
}

function card_label(array $c): string { return $c['r'].$c['s']; }

// Blackjack values
function bj_hand_value(array $hand): array {
  // returns [total, soft] where soft indicates ace counted as 11
  $total = 0;
  $aces = 0;

  foreach ($hand as $c) {
    $r = $c['r'];
    if ($r === 'A') { $aces++; $total += 11; }
    elseif (in_array($r, ['K','Q','J'], true)) $total += 10;
    else $total += (int)$r;
  }

  $soft = $aces > 0;
  while ($total > 21 && $aces > 0) {
    $total -= 10; // turn one Ace from 11 to 1
    $aces--;
  }
  // soft if at least one ace still counted as 11
  $soft = false;
  $aces2 = 0;
  foreach ($hand as $c) if ($c['r'] === 'A') $aces2++;
  // if there is an ace and total+10 <=21? more robust:
  // we'll compute "soft" by checking if adding 10 would bust vs a baseline-all-aces-as-1
  $base = 0; $aces3 = 0;
  foreach ($hand as $c) {
    $r = $c['r'];
    if ($r === 'A') { $aces3++; $base += 1; }
    elseif (in_array($r, ['K','Q','J'], true)) $base += 10;
    else $base += (int)$r;
  }
  if ($aces3 > 0 && ($base + 10) <= 21) $soft = true;

  return ['total' => $total, 'soft' => $soft];
}

function bj_is_blackjack(array $hand): bool {
  if (count($hand) !== 2) return false;
  $r1 = $hand[0]['r']; $r2 = $hand[1]['r'];
  $tenLike = fn($r) => in_array($r, ['10','J','Q','K'], true);
  return ($r1 === 'A' && $tenLike($r2)) || ($r2 === 'A' && $tenLike($r1));
}

// Baccarat values
function bac_card_value(array $c): int {
  $r = $c['r'];
  if ($r === 'A') return 1;
  if (in_array($r, ['10','J','Q','K'], true)) return 0;
  return (int)$r;
}

function bac_hand_total(array $hand): int {
  $sum = 0;
  foreach ($hand as $c) $sum += bac_card_value($c);
  return $sum % 10;
}

/* -----------------------------
   BLACKJACK ENGINE (session state + AJAX)
------------------------------*/

function bj_reset_round(): void {
  $bj = &$_SESSION['casino']['bj'];
  $bj['handId'] = null;
  $bj['phase'] = 'idle';
  $bj['deck'] = [];
  $bj['player'] = [];
  $bj['dealer'] = [];
  $bj['result'] = null;
}

function bj_snapshot(): array {
  $bj = $_SESSION['casino']['bj'];
  $p = bj_hand_value($bj['player']);
  $d = bj_hand_value($bj['dealer']);
  return [
    'handId' => $bj['handId'],
    'phase' => $bj['phase'],
    'player' => $bj['player'],
    'dealer' => $bj['dealer'],
    'playerTotal' => $p['total'] ?? 0,
    'playerSoft' => $p['soft'] ?? false,
    'dealerTotal' => $d['total'] ?? 0,
    'dealerSoft' => $d['soft'] ?? false,
    'result' => $bj['result'],
    'history' => $bj['history'],
  ];
}

function bj_record_history(array $entry): void {
  $bj = &$_SESSION['casino']['bj'];
  array_unshift($bj['history'], $entry);
  $bj['history'] = array_slice($bj['history'], 0, BJ_MAX_HISTORY);
}

function bj_start(): array {
  $bj = &$_SESSION['casino']['bj'];
  $bj['deck'] = build_deck(1);
  $bj['player'] = [draw_card($bj['deck']), draw_card($bj['deck'])];
  $bj['dealer'] = [draw_card($bj['deck']), draw_card($bj['deck'])];
  $bj['handId'] = bin2hex(random_bytes(6));
  $bj['phase'] = 'player';
  $bj['result'] = null;

  $pBJ = bj_is_blackjack($bj['player']);
  $dBJ = bj_is_blackjack($bj['dealer']);

  if ($pBJ || $dBJ) {
    $bj['phase'] = 'complete';
    if ($pBJ && $dBJ) $bj['result'] = ['outcome' => 'push', 'label' => 'Push (both blackjack)'];
    elseif ($pBJ) $bj['result'] = ['outcome' => 'win', 'label' => 'Blackjack! You win'];
    else $bj['result'] = ['outcome' => 'lose', 'label' => 'Dealer has blackjack. You lose'];

    bj_record_history([
      'time' => nowIso(),
      'handId' => $bj['handId'],
      'outcome' => $bj['result']['outcome'],
      'label' => $bj['result']['label'],
      'player' => $bj['player'],
      'dealer' => $bj['dealer'],
      'playerTotal' => bj_hand_value($bj['player'])['total'],
      'dealerTotal' => bj_hand_value($bj['dealer'])['total'],
    ]);
  }

  return bj_snapshot();
}

function bj_hit(): array {
  $bj = &$_SESSION['casino']['bj'];
  if ($bj['phase'] !== 'player') return bj_snapshot();

  $bj['player'][] = draw_card($bj['deck']);
  $p = bj_hand_value($bj['player']);
  if ($p['total'] > 21) {
    $bj['phase'] = 'complete';
    $bj['result'] = ['outcome' => 'lose', 'label' => 'Bust. You lose'];

    bj_record_history([
      'time' => nowIso(),
      'handId' => $bj['handId'],
      'outcome' => $bj['result']['outcome'],
      'label' => $bj['result']['label'],
      'player' => $bj['player'],
      'dealer' => $bj['dealer'],
      'playerTotal' => $p['total'],
      'dealerTotal' => bj_hand_value($bj['dealer'])['total'],
    ]);
  }
  return bj_snapshot();
}

function bj_dealer_play(): void {
  $bj = &$_SESSION['casino']['bj'];
  // Dealer hits until 17+. Common rule: hit on soft 16, stand on soft 17.
  while (true) {
    $d = bj_hand_value($bj['dealer']);
    $total = $d['total'];
    $soft = $d['soft'];

    if ($total < 17) {
      $bj['dealer'][] = draw_card($bj['deck']);
      continue;
    }
    if ($total === 17 && $soft === true) {
      // Stand on soft 17 (common). If you want dealer to hit soft 17, change to hit.
      break;
    }
    break;
  }
}

function bj_stand(): array {
  $bj = &$_SESSION['casino']['bj'];
  if ($bj['phase'] !== 'player') return bj_snapshot();

  $bj['phase'] = 'dealer';
  bj_dealer_play();
  $bj['phase'] = 'complete';

  $p = bj_hand_value($bj['player']);
  $d = bj_hand_value($bj['dealer']);

  if ($d['total'] > 21) $bj['result'] = ['outcome' => 'win', 'label' => 'Dealer busts. You win'];
  else {
    if ($p['total'] > $d['total']) $bj['result'] = ['outcome' => 'win', 'label' => 'You win'];
    elseif ($p['total'] < $d['total']) $bj['result'] = ['outcome' => 'lose', 'label' => 'You lose'];
    else $bj['result'] = ['outcome' => 'push', 'label' => 'Push'];
  }

  bj_record_history([
    'time' => nowIso(),
    'handId' => $bj['handId'],
    'outcome' => $bj['result']['outcome'],
    'label' => $bj['result']['label'],
    'player' => $bj['player'],
    'dealer' => $bj['dealer'],
    'playerTotal' => $p['total'],
    'dealerTotal' => $d['total'],
  ]);

  return bj_snapshot();
}

/* -----------------------------
   BACCARAT ENGINE (session state + AJAX)
------------------------------*/

function bac_snapshot(): array {
  $b = $_SESSION['casino']['bac'];
  return [
    'handId' => $b['handId'],
    'phase' => $b['phase'],
    'player' => $b['player'],
    'banker' => $b['banker'],
    'playerTotal' => bac_hand_total($b['player']),
    'bankerTotal' => bac_hand_total($b['banker']),
    'result' => $b['result'],
    'history' => $b['history'],
  ];
}

function bac_record_history(array $entry): void {
  $b = &$_SESSION['casino']['bac'];
  array_unshift($b['history'], $entry);
  $b['history'] = array_slice($b['history'], 0, BAC_MAX_HISTORY);
}

function bac_reset_round(): void {
  $b = &$_SESSION['casino']['bac'];
  $b['handId'] = null;
  $b['phase'] = 'idle';
  $b['deck'] = [];
  $b['player'] = [];
  $b['banker'] = [];
  $b['result'] = null;
}

/**
 * Baccarat drawing rules (standard / simplified, close to casino rules):
 * - Deal 2 cards each: Player, Banker
 * - Natural: if either total is 8 or 9 => stand
 * - Player draws third if Player total <= 5
 * - Banker draws based on Banker total and Player third card (if any)
 */
function bac_deal(): array {
  $b = &$_SESSION['casino']['bac'];
  $b['deck'] = build_deck(1);
  $b['player'] = [draw_card($b['deck']), draw_card($b['deck'])];
  $b['banker'] = [draw_card($b['deck']), draw_card($b['deck'])];
  $b['handId'] = bin2hex(random_bytes(6));
  $b['phase'] = 'complete';
  $b['result'] = null;

  $pTotal = bac_hand_total($b['player']);
  $bTotal = bac_hand_total($b['banker']);

  $natural = ($pTotal >= 8 || $bTotal >= 8);

  $playerThird = null;

  if (!$natural) {
    if ($pTotal <= 5) {
      $playerThird = draw_card($b['deck']);
      $b['player'][] = $playerThird;
      $pTotal = bac_hand_total($b['player']);
    }

    $bTotal = bac_hand_total($b['banker']);

    // Banker drawing rules
    if ($playerThird === null) {
      // Player stands on 6/7: Banker draws if total <=5
      if ($bTotal <= 5) {
        $b['banker'][] = draw_card($b['deck']);
      }
    } else {
      $pt = bac_card_value($playerThird);
      if ($bTotal <= 2) $b['banker'][] = draw_card($b['deck']);
      elseif ($bTotal === 3 && $pt !== 8) $b['banker'][] = draw_card($b['deck']);
      elseif ($bTotal === 4 && ($pt >= 2 && $pt <= 7)) $b['banker'][] = draw_card($b['deck']);
      elseif ($bTotal === 5 && ($pt >= 4 && $pt <= 7)) $b['banker'][] = draw_card($b['deck']);
      elseif ($bTotal === 6 && ($pt === 6 || $pt === 7)) $b['banker'][] = draw_card($b['deck']);
      // 7 stands
    }
  }

  $pTotal = bac_hand_total($b['player']);
  $bTotal = bac_hand_total($b['banker']);

  if ($pTotal > $bTotal) $b['result'] = ['outcome' => 'player', 'label' => 'Player wins'];
  elseif ($pTotal < $bTotal) $b['result'] = ['outcome' => 'banker', 'label' => 'Banker wins'];
  else $b['result'] = ['outcome' => 'tie', 'label' => 'Tie'];

  bac_record_history([
    'time' => nowIso(),
    'handId' => $b['handId'],
    'outcome' => $b['result']['outcome'],
    'label' => $b['result']['label'],
    'player' => $b['player'],
    'banker' => $b['banker'],
    'playerTotal' => $pTotal,
    'bankerTotal' => $bTotal,
    'natural' => $natural,
  ]);

  return bac_snapshot();
}

/* -----------------------------
   AJAX API (single-file endpoint)
------------------------------*/

$raw = file_get_contents('php://input');
$isApi = ($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_GET['api']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

if ($isApi) {
  $data = [];
  if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $data = $decoded;
  } else {
    // fallback for form encoded
    $data = $_POST;
  }

  $op = $data['op'] ?? '';
  $csrf = $data['csrf'] ?? null;
  require_csrf(is_string($csrf) ? $csrf : null);

  // Ensure state exists
  init_state();

  // -------- Lottery ops
  if ($op === 'lotto_spin') {
    $picksIn = $data['picks'] ?? null;
    if (!is_array($picksIn) || count($picksIn) !== LOTTO_SLOTS) {
      json_out(API_BAD, ['ok'=>false,'error'=>'Provide picks as an array of 5 numbers.']);
    }
    $picks = [];
    for ($i=0; $i<LOTTO_SLOTS; $i++) {
      $v = clamp_int($picksIn[$i] ?? null, MIN_N, MAX_N);
      if ($v === null) json_out(API_BAD, ['ok'=>false,'error'=>'Each pick must be an integer 1–10.']);
      $picks[] = $v;
    }

    $draw = lotto_draw_biased($picks);
    $metrics = lotto_evaluate($picks, $draw);
    $tier = lotto_classify($metrics);
    lotto_record($picks, $draw, $metrics, $tier);

    json_out(API_OK, [
      'ok' => true,
      'result' => [
        'time' => nowIso(),
        'picks' => $picks,
        'draw' => $draw,
        'metrics' => $metrics,
        'tier' => $tier,
      ],
      'stats' => lotto_stats_payload(),
    ]);
  }

  if ($op === 'lotto_reset_stats') {
    $_SESSION['casino']['lotto'] = [
      'spins' => 0, 'wins' => 0, 'bestTier' => null, 'bestExact' => 0,
      'streakWin' => 0, 'streakLose' => 0, 'history' => [],
    ];
    json_out(API_OK, ['ok'=>true, 'stats'=>lotto_stats_payload()]);
  }

  if ($op === 'lotto_get') {
    json_out(API_OK, ['ok'=>true, 'stats'=>lotto_stats_payload()]);
  }

  // -------- Blackjack ops
  if ($op === 'bj_start') {
    // starting a new hand implicitly resets current
    $snap = bj_start();
    json_out(API_OK, ['ok'=>true, 'snap'=>$snap]);
  }
  if ($op === 'bj_hit') {
    $snap = bj_hit();
    json_out(API_OK, ['ok'=>true, 'snap'=>$snap]);
  }
  if ($op === 'bj_stand') {
    $snap = bj_stand();
    json_out(API_OK, ['ok'=>true, 'snap'=>$snap]);
  }
  if ($op === 'bj_reset') {
    bj_reset_round();
    json_out(API_OK, ['ok'=>true, 'snap'=>bj_snapshot()]);
  }
  if ($op === 'bj_get') {
    json_out(API_OK, ['ok'=>true, 'snap'=>bj_snapshot()]);
  }

  // -------- Baccarat ops
  if ($op === 'bac_deal') {
    $snap = bac_deal();
    json_out(API_OK, ['ok'=>true, 'snap'=>$snap]);
  }
  if ($op === 'bac_reset') {
    bac_reset_round();
    json_out(API_OK, ['ok'=>true, 'snap'=>bac_snapshot()]);
  }
  if ($op === 'bac_get') {
    json_out(API_OK, ['ok'=>true, 'snap'=>bac_snapshot()]);
  }

  json_out(API_BAD, ['ok'=>false, 'error'=>'Unknown operation.']);
}

// Non-API: render page
$csrf = $_SESSION['csrf'];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Casino Single-Page Engine</title>
  <style>
    :root{
      --bg1:#0b1220; --bg2:#05060a;
      --card: rgba(255,255,255,0.06);
      --border: rgba(255,255,255,0.12);
      --text: rgba(255,255,255,0.92);
      --muted: rgba(255,255,255,0.70);
      --green:#16a34a; --red:#ef4444; --amber:#f59e0b; --cyan:#22d3ee; --violet:#a78bfa;
    }
    *{ box-sizing:border-box; }
    body{
      margin:0; min-height:100vh; display:grid; place-items:center; padding:22px;
      background: radial-gradient(circle at top, var(--bg1), var(--bg2));
      color:var(--text);
      font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Apple Color Emoji","Segoe UI Emoji";
    }
    .wrap{ width:min(1180px, 100%); display:grid; gap:14px; }
    .card{
      border-radius:18px; padding:18px;
      background:var(--card); border:1px solid var(--border);
      box-shadow: 0 20px 60px rgba(0,0,0,0.45);
    }
    h1{ margin:0; font-size:28px; letter-spacing:.2px; }
    .sub{ margin-top:8px; color:var(--muted); line-height:1.45; }
    .top{
      display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;
    }
    .tabs{ display:flex; gap:8px; flex-wrap:wrap; }
    .tab{
      height:42px; padding:0 14px; border-radius:999px;
      border:1px solid rgba(255,255,255,0.14);
      background: rgba(0,0,0,0.20);
      color: rgba(255,255,255,0.86);
      font-weight:900; cursor:pointer;
      display:inline-flex; align-items:center; justify-content:center;
    }
    .tab.active{
      border-color: rgba(34,211,238,0.35);
      box-shadow: 0 0 0 3px rgba(34,211,238,0.15);
      background: rgba(255,255,255,0.08);
    }
    .grid{ display:grid; grid-template-columns: 1.25fr .75fr; gap:14px; }
    @media (max-width: 980px){ .grid{ grid-template-columns:1fr; } }

    .sectionTitle{ font-size:18px; font-weight:1000; margin:0 0 10px; }
    .muted{ color:var(--muted); }
    .hidden{ display:none !important; }

    /* Shared UI bits */
    .actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    button{
      height:44px; padding:0 16px; border-radius:12px;
      border:1px solid rgba(255,255,255,0.20);
      background: rgba(255,255,255,0.12);
      color:#fff; font-weight:900; cursor:pointer;
    }
    button.secondary{
      background: transparent;
      border:1px solid rgba(255,255,255,0.16);
      color: rgba(255,255,255,0.85);
      font-weight:800;
    }
    button.danger{
      border-color: rgba(239,68,68,0.35);
      background: rgba(239,68,68,0.10);
    }
    .error{
      margin-top:12px; padding:12px; border-radius:12px;
      background: rgba(239,68,68,0.12);
      border:1px solid rgba(239,68,68,0.35);
    }
    .ok{
      margin-top:12px; padding:12px; border-radius:12px;
      background: rgba(22,163,74,0.12);
      border:1px solid rgba(22,163,74,0.35);
    }

    .pillrow{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
    .pill{
      padding:8px 10px; border-radius:999px;
      border:1px solid rgba(255,255,255,0.14);
      background: rgba(0,0,0,0.20);
      color: rgba(255,255,255,0.86);
      font-weight:800; font-size:12px;
      white-space:nowrap;
    }

    /* Balls / cards */
    .balls{ display:flex; gap:10px; flex-wrap:wrap; }
    .ball{
      width:46px; height:46px; border-radius:999px;
      display:inline-flex; align-items:center; justify-content:center;
      background: rgba(255,255,255,0.08);
      border:2px solid rgba(255,255,255,0.25);
      font-size:18px; font-weight:1000;
    }
    .match{ border-color: var(--green); box-shadow: 0 0 0 3px rgba(22,163,74,0.25); }
    .near{ border-color: rgba(245,158,11,0.55); box-shadow: 0 0 0 3px rgba(245,158,11,0.12); }

    .hand{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .cardchip{
      min-width:54px;
      height:46px;
      padding:0 12px;
      border-radius:14px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background: rgba(255,255,255,0.07);
      border:1px solid rgba(255,255,255,0.16);
      font-size:18px;
      font-weight:1000;
      letter-spacing:.2px;
    }

    /* Lottery specific */
    .slots{ display:grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap:10px; }
    @media (max-width: 720px){ .slots{ grid-template-columns:1fr; } }

    .slot{
      padding:12px; border-radius:14px;
      background: rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.10);
    }
    .label{ font-size:12px; color:var(--muted); margin-bottom:8px; }
    select{
      width:100%; height:44px; border-radius:12px;
      background: rgba(0,0,0,0.25);
      color:#fff; border:1px solid rgba(255,255,255,0.20);
      outline:none; padding:0 10px; font-size:16px; font-weight:900;
    }

    .result{
      display:grid; gap:10px;
      padding:14px; border-radius:16px;
      background: rgba(0,0,0,0.22);
      border:1px solid rgba(255,255,255,0.10);
      margin-top:12px;
    }
    .row{ display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .row-label{ width:92px; color:var(--muted); font-size:13px; }

    .tier{
      padding:10px 12px; border-radius:14px;
      border:1px solid rgba(255,255,255,0.12);
      background: rgba(255,255,255,0.06);
      font-weight:1000;
    }
    .tier small{ display:block; font-weight:800; color:var(--muted); margin-top:4px; }

    .tier-jackpot{ border-color: rgba(34,211,238,0.35); box-shadow: 0 0 0 3px rgba(34,211,238,0.15); }
    .tier-1{ border-color: rgba(167,139,250,0.35); box-shadow: 0 0 0 3px rgba(167,139,250,0.12); }
    .tier-2{ border-color: rgba(245,158,11,0.40); box-shadow: 0 0 0 3px rgba(245,158,11,0.12); }
    .tier-3{ border-color: rgba(22,163,74,0.35); box-shadow: 0 0 0 3px rgba(22,163,74,0.12); }
    .tier-cons{ border-color: rgba(255,255,255,0.22); }
    .tier-loss{ border-color: rgba(239,68,68,0.35); box-shadow: 0 0 0 3px rgba(239,68,68,0.10); }

    .metrics{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:10px; }
    .metric{
      padding:12px; border-radius:14px;
      background: rgba(255,255,255,0.05);
      border:1px solid rgba(255,255,255,0.10);
    }
    .metric .k{ font-size:12px; color:var(--muted); }
    .metric .v{ font-size:18px; font-weight:1000; margin-top:4px; }

    .hist{
      display:grid; gap:10px;
      max-height: 420px; overflow:auto; padding-right:4px;
    }
    .histitem{
      padding:12px; border-radius:14px;
      background: rgba(255,255,255,0.04);
      border:1px solid rgba(255,255,255,0.10);
    }
    .histitem .t{ font-weight:1000; }
    .mini{ margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; }
    .chip{
      padding:6px 10px; border-radius:999px;
      border:1px solid rgba(255,255,255,0.14);
      background: rgba(0,0,0,0.20);
      font-weight:900; font-size:12px;
      color: rgba(255,255,255,0.86);
    }
    .footer-note{ color:var(--muted); font-size:12px; margin-top:10px; line-height:1.4; }
  </style>
</head>

<body>
  <div class="wrap">
    <div class="card">
      <div class="top">
        <div>
          <h1>🎲 Casino Single-Page Engine</h1>
          <div class="sub">
            Lottery (tiers + stats) + Blackjack + Baccarat — one file, AJAX-powered, session state, and per-game history.
          </div>
        </div>
        <div class="tabs" role="tablist">
          <button class="tab active" id="tab-lotto" onclick="UI.switchGame('lotto')" type="button">🎰 Lottery</button>
          <button class="tab" id="tab-bj" onclick="UI.switchGame('bj')" type="button">🂡 Blackjack</button>
          <button class="tab" id="tab-bac" onclick="UI.switchGame('bac')" type="button">🎴 Baccarat</button>
        </div>
      </div>
      <div class="pillrow" style="margin-top:12px;">
        <div class="pill">Slots: <?php echo LOTTO_SLOTS; ?></div>
        <div class="pill">Lottery range: <?php echo MIN_N; ?>–<?php echo MAX_N; ?></div>
        <div class="pill">Lottery bias: <?php echo LOTTO_ENABLE_BIAS ? "ON (".h((string)LOTTO_BIAS_FACTOR).")" : "OFF"; ?></div>
        <div class="pill">Session-based play</div>
      </div>
    </div>

    <div class="grid">
      <!-- LEFT: Active Game -->
      <div class="card">
        <!-- Lottery Panel -->
        <div id="panel-lotto">
          <div class="sectionTitle">🎰 Lottery</div>
          <div class="muted">Pick 5 numbers (1–10). Spin runs server-side and returns a tiered outcome.</div>

          <div style="margin-top:12px;">
            <div class="slots" id="lotto-slots">
              <?php for ($i=0; $i<LOTTO_SLOTS; $i++): ?>
                <div class="slot">
                  <div class="label">Slot <?php echo $i+1; ?></div>
                  <select id="lotto-pick-<?php echo $i; ?>">
                    <?php for ($n=MIN_N; $n<=MAX_N; $n++): ?>
                      <option value="<?php echo $n; ?>"><?php echo $n; ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              <?php endfor; ?>
            </div>

            <div class="actions">
              <button type="button" onclick="Lotto.spin()">Spin (AJAX)</button>
              <button type="button" class="secondary" onclick="Lotto.quickPick()">Quick Pick</button>
              <button type="button" class="secondary danger" onclick="Lotto.resetStats()">Reset Lottery Stats</button>
            </div>

            <div id="lotto-msg"></div>

            <div id="lotto-result" class="result hidden"></div>
          </div>
        </div>

        <!-- Blackjack Panel -->
        <div id="panel-bj" class="hidden">
          <div class="sectionTitle">🂡 Blackjack</div>
          <div class="muted">Single-player vs dealer. Start a hand, Hit/Stand. Hand history persists while you switch tabs.</div>

          <div class="actions" style="margin-top:12px;">
            <button type="button" onclick="BJ.start()">Start Hand</button>
            <button type="button" class="secondary" onclick="BJ.hit()">Hit</button>
            <button type="button" class="secondary" onclick="BJ.stand()">Stand</button>
            <button type="button" class="secondary danger" onclick="BJ.resetRound()">Reset Round</button>
          </div>

          <div id="bj-msg"></div>

          <div id="bj-active" class="result">
            <div class="row">
              <div class="row-label">Player</div>
              <div class="hand" id="bj-player"></div>
              <div class="pill" id="bj-player-total"></div>
            </div>
            <div class="row">
              <div class="row-label">Dealer</div>
              <div class="hand" id="bj-dealer"></div>
              <div class="pill" id="bj-dealer-total"></div>
            </div>
            <div class="row">
              <div class="row-label">Result</div>
              <div class="tier" id="bj-result">—</div>
            </div>
            <div class="footer-note">Tip: “Start Hand” begins a new hand. Hit/Stand only work while phase is Player.</div>
          </div>
        </div>

        <!-- Baccarat Panel -->
        <div id="panel-bac" class="hidden">
          <div class="sectionTitle">🎴 Baccarat</div>
          <div class="muted">Press Deal. The engine follows standard third-card rules and returns Player/Banker/Tie.</div>

          <div class="actions" style="margin-top:12px;">
            <button type="button" onclick="Bac.deal()">Deal (AJAX)</button>
            <button type="button" class="secondary danger" onclick="Bac.resetRound()">Reset Round</button>
          </div>

          <div id="bac-msg"></div>

          <div id="bac-active" class="result">
            <div class="row">
              <div class="row-label">Player</div>
              <div class="hand" id="bac-player"></div>
              <div class="pill" id="bac-player-total"></div>
            </div>
            <div class="row">
              <div class="row-label">Banker</div>
              <div class="hand" id="bac-banker"></div>
              <div class="pill" id="bac-banker-total"></div>
            </div>
            <div class="row">
              <div class="row-label">Result</div>
              <div class="tier" id="bac-result">—</div>
            </div>
            <div class="footer-note">Note: Totals are modulo 10. Tens/face cards count as 0.</div>
          </div>
        </div>
      </div>

      <!-- RIGHT: History/Stats for current game -->
      <div class="card">
        <div class="sectionTitle" id="right-title">📊 Lottery Stats & History</div>

        <!-- Lottery right -->
        <div id="right-lotto">
          <div class="metrics">
            <div class="metric"><div class="k">Spins</div><div class="v" id="lotto-spins">0</div></div>
            <div class="metric"><div class="k">Wins</div><div class="v" id="lotto-wins">0</div></div>
            <div class="metric"><div class="k">Win Rate</div><div class="v" id="lotto-winrate">0%</div></div>
            <div class="metric"><div class="k">Best Tier</div><div class="v" id="lotto-besttier">—</div></div>
            <div class="metric"><div class="k">Best Exact</div><div class="v" id="lotto-bestexact">0/5</div></div>
            <div class="metric"><div class="k">Streaks</div><div class="v" id="lotto-streaks">W0 / L0</div></div>
          </div>

          <div style="margin-top:14px; font-weight:1000; font-size:16px;">🕒 Lottery Spins</div>
          <div class="hist" id="lotto-history" style="margin-top:10px;"></div>
          <div class="footer-note">Lottery history is stored in your session. Closing the browser may clear it depending on your setup.</div>
        </div>

        <!-- Blackjack right -->
        <div id="right-bj" class="hidden">
          <div style="margin-bottom:10px;" class="muted">Previous hands and results are listed here while you keep playing.</div>
          <div class="hist" id="bj-history"></div>
          <div class="footer-note">Blackjack rules: dealer stands on soft 17. Blackjack auto-resolves on deal.</div>
        </div>

        <!-- Baccarat right -->
        <div id="right-bac" class="hidden">
          <div style="margin-bottom:10px;" class="muted">Previous baccarat hands are listed here. Deal repeatedly for rapid play.</div>
          <div class="hist" id="bac-history"></div>
          <div class="footer-note">Baccarat uses a close-to-standard third-card ruleset for Banker based on Player’s third card.</div>
        </div>
      </div>
    </div>
  </div>

<script>
const CSRF = <?php echo json_encode($csrf); ?>;

const API = {
  async post(op, payload = {}) {
    const res = await fetch('?api=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ op, csrf: CSRF, ...payload })
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      const err = data && data.error ? data.error : 'Request failed.';
      throw new Error(err);
    }
    return data;
  }
};

const UI = {
  current: 'lotto',
  switchGame(game) {
    this.current = game;
    // tabs
    document.getElementById('tab-lotto').classList.toggle('active', game === 'lotto');
    document.getElementById('tab-bj').classList.toggle('active', game === 'bj');
    document.getElementById('tab-bac').classList.toggle('active', game === 'bac');

    // panels
    document.getElementById('panel-lotto').classList.toggle('hidden', game !== 'lotto');
    document.getElementById('panel-bj').classList.toggle('hidden', game !== 'bj');
    document.getElementById('panel-bac').classList.toggle('hidden', game !== 'bac');

    // right panels
    document.getElementById('right-lotto').classList.toggle('hidden', game !== 'lotto');
    document.getElementById('right-bj').classList.toggle('hidden', game !== 'bj');
    document.getElementById('right-bac').classList.toggle('hidden', game !== 'bac');

    document.getElementById('right-title').textContent =
      game === 'lotto' ? '📊 Lottery Stats & History' :
      game === 'bj' ? '🕒 Blackjack Hand History' :
      '🕒 Baccarat Hand History';

    // refresh view snapshot for selected game
    if (game === 'lotto') Lotto.refresh();
    if (game === 'bj') BJ.refresh();
    if (game === 'bac') Bac.refresh();
  },

  msg(elId, type, text) {
    const el = document.getElementById(elId);
    if (!el) return;
    if (!text) { el.innerHTML = ''; return; }
    const cls = type === 'error' ? 'error' : 'ok';
    el.innerHTML = `<div class="${cls}">${escapeHtml(text)}</div>`;
  }
};

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

function renderBalls(nums, opts = {}) {
  const { highlightMask = [], nearMask = [] } = opts;
  return nums.map((n, i) => {
    const cls = [
      'ball',
      highlightMask[i] ? 'match' : '',
      (!highlightMask[i] && nearMask[i]) ? 'near' : ''
    ].filter(Boolean).join(' ');
    return `<div class="${cls}">${n}</div>`;
  }).join('');
}

function renderCards(cards) {
  if (!cards || !cards.length) return `<span class="muted">—</span>`;
  return cards.map(c => `<div class="cardchip">${escapeHtml(c.r + c.s)}</div>`).join('');
}

/* ---------------------------
   Lottery client
----------------------------*/
const Lotto = {
  picks() {
    const arr = [];
    for (let i=0;i<5;i++) {
      const v = Number(document.getElementById(`lotto-pick-${i}`).value);
      arr.push(v);
    }
    return arr;
  },

  quickPick() {
    for (let i=0;i<5;i++) {
      const n = Math.floor(Math.random() * (10 - 1 + 1)) + 1;
      document.getElementById(`lotto-pick-${i}`).value = String(n);
    }
    UI.msg('lotto-msg', 'ok', 'Quick Pick ready — hit Spin!');
  },

  async spin() {
    UI.msg('lotto-msg', '', '');
    try {
      const picks = this.picks();
      const data = await API.post('lotto_spin', { picks });
      this.renderResult(data.result);
      this.renderStats(data.stats);
      UI.msg('lotto-msg', 'ok', 'Spin complete.');
    } catch (e) {
      UI.msg('lotto-msg', 'error', e.message);
    }
  },

  async resetStats() {
    UI.msg('lotto-msg', '', '');
    try {
      const data = await API.post('lotto_reset_stats', {});
      this.renderStats(data.stats);
      document.getElementById('lotto-result').classList.add('hidden');
      UI.msg('lotto-msg', 'ok', 'Lottery stats reset.');
    } catch (e) {
      UI.msg('lotto-msg', 'error', e.message);
    }
  },

  async refresh() {
    try {
      const data = await API.post('lotto_get', {});
      this.renderStats(data.stats);
    } catch (e) {
      // silent
    }
  },

  renderResult(r) {
    const nearMask = r.picks.map((p, i) => (!r.metrics.mask[i] && Math.abs(p - r.draw[i]) === 1));
    const el = document.getElementById('lotto-result');
    el.classList.remove('hidden');
    el.innerHTML = `
      <div class="row" style="justify-content:space-between; gap:10px;">
        <div class="tier ${escapeHtml(r.tier.style)}">
          ${escapeHtml(r.tier.label)}
          <small>${escapeHtml(r.tier.desc)}</small>
        </div>
        <div class="pill">Exact: ${r.metrics.exact}/5</div>
        <div class="pill">Any-order: ${r.metrics.anyOrder}/5</div>
        <div class="pill">Near: ${r.metrics.near}</div>
      </div>

      <div class="row">
        <div class="row-label">Your Picks</div>
        <div class="balls">${renderBalls(r.picks)}</div>
      </div>

      <div class="row">
        <div class="row-label">Draw</div>
        <div class="balls">${renderBalls(r.draw, { highlightMask: r.metrics.mask, nearMask })}</div>
      </div>

      <div class="metrics">
        <div class="metric"><div class="k">Tier Key</div><div class="v">${escapeHtml(r.tier.key)}</div></div>
        <div class="metric"><div class="k">Adaptation Hint</div><div class="v">${hintFromMetrics(r.metrics)}</div></div>
        <div class="metric"><div class="k">Timestamp</div><div class="v">${escapeHtml(r.time)}</div></div>
      </div>
      <div class="footer-note">
        Tier logic: Jackpot=5 exact; Tier1=4 exact; Tier2=3 exact OR 5 any-order; Tier3=2 exact OR 4 any-order; Consolation=1+ exact OR 3+ any-order; else Loss.
      </div>
    `;
  },

  renderStats(s) {
    document.getElementById('lotto-spins').textContent = String(s.spins);
    document.getElementById('lotto-wins').textContent = String(s.wins);
    document.getElementById('lotto-winrate').textContent = String(s.winRate) + '%';
    document.getElementById('lotto-besttier').textContent = s.bestTierLabel ? s.bestTierLabel : '—';
    document.getElementById('lotto-bestexact').textContent = `${s.bestExact}/5`;
    document.getElementById('lotto-streaks').textContent = `W${s.streakWin} / L${s.streakLose}`;
    this.renderHistory(s.history || []);
  },

  renderHistory(items) {
    const el = document.getElementById('lotto-history');
    if (!items.length) {
      el.innerHTML = `<div class="histitem">No spins yet. Use Quick Pick or choose numbers and Spin.</div>`;
      return;
    }
    el.innerHTML = items.map(it => `
      <div class="histitem">
        <div class="t">${escapeHtml(it.tierLabel)} <span class="muted" style="font-weight:800;">• ${escapeHtml(it.time)}</span></div>
        <div class="mini">
          <span class="chip">Exact: ${it.metrics.exact}</span>
          <span class="chip">Any-order: ${it.metrics.anyOrder}</span>
          <span class="chip">Near: ${it.metrics.near}</span>
        </div>
        <div class="mini" style="margin-top:10px;">
          <span class="chip">Picks: ${escapeHtml((it.picks||[]).join('-'))}</span>
          <span class="chip">Draw: ${escapeHtml((it.draw||[]).join('-'))}</span>
        </div>
      </div>
    `).join('');
  }
};

function hintFromMetrics(m) {
  if (m.exact >= 4) return 'Lock it in';
  if (m.anyOrder >= 4) return 'Good combo';
  if (m.near >= 2) return 'So close';
  return 'Switch it up';
}

/* ---------------------------
   Blackjack client
----------------------------*/
const BJ = {
  async refresh() {
    UI.msg('bj-msg', '', '');
    try {
      const data = await API.post('bj_get', {});
      this.render(data.snap);
    } catch (e) {
      UI.msg('bj-msg', 'error', e.message);
    }
  },

  async start() {
    UI.msg('bj-msg', '', '');
    try {
      const data = await API.post('bj_start', {});
      this.render(data.snap);
      UI.msg('bj-msg', 'ok', 'Hand started.');
    } catch (e) {
      UI.msg('bj-msg', 'error', e.message);
    }
  },

  async hit() {
    UI.msg('bj-msg', '', '');
    try {
      const data = await API.post('bj_hit', {});
      this.render(data.snap);
    } catch (e) {
      UI.msg('bj-msg', 'error', e.message);
    }
  },

  async stand() {
    UI.msg('bj-msg', '', '');
    try {
      const data = await API.post('bj_stand', {});
      this.render(data.snap);
    } catch (e) {
      UI.msg('bj-msg', 'error', e.message);
    }
  },

  async resetRound() {
    UI.msg('bj-msg', '', '');
    try {
      const data = await API.post('bj_reset', {});
      this.render(data.snap);
      UI.msg('bj-msg', 'ok', 'Round reset.');
    } catch (e) {
      UI.msg('bj-msg', 'error', e.message);
    }
  },

  render(snap) {
    // Active hands
    document.getElementById('bj-player').innerHTML = renderCards(snap.player || []);
    document.getElementById('bj-dealer').innerHTML = renderCards(snap.dealer || []);
    document.getElementById('bj-player-total').textContent = snap.player && snap.player.length ? `Total: ${snap.playerTotal}${snap.playerSoft ? ' (soft)' : ''}` : 'Total: —';
    document.getElementById('bj-dealer-total').textContent = snap.dealer && snap.dealer.length ? `Total: ${snap.dealerTotal}${snap.dealerSoft ? ' (soft)' : ''}` : 'Total: —';

    // Result
    const rEl = document.getElementById('bj-result');
    if (snap.result && snap.result.label) {
      const cls = snap.result.outcome === 'win' ? 'tier tier-3' :
                  snap.result.outcome === 'lose' ? 'tier tier-loss' : 'tier tier-cons';
      rEl.className = cls;
      rEl.textContent = snap.result.label;
    } else {
      rEl.className = 'tier';
      rEl.textContent = snap.phase === 'player' ? 'Your move (Hit/Stand)' : (snap.phase === 'idle' ? '—' : 'In progress');
    }

    this.renderHistory(snap.history || []);
  },

  renderHistory(items) {
    const el = document.getElementById('bj-history');
    if (!items.length) {
      el.innerHTML = `<div class="histitem">No blackjack hands yet. Click <b>Start Hand</b>.</div>`;
      return;
    }
    el.innerHTML = items.map(it => `
      <div class="histitem">
        <div class="t">${escapeHtml(it.label)} <span class="muted" style="font-weight:800;">• ${escapeHtml(it.time)}</span></div>
        <div class="mini">
          <span class="chip">Player: ${escapeHtml((it.player||[]).map(c => c.r + c.s).join(' '))} (${it.playerTotal})</span>
        </div>
        <div class="mini">
          <span class="chip">Dealer: ${escapeHtml((it.dealer||[]).map(c => c.r + c.s).join(' '))} (${it.dealerTotal})</span>
        </div>
      </div>
    `).join('');
  }
};

/* ---------------------------
   Baccarat client
----------------------------*/
const Bac = {
  async refresh() {
    UI.msg('bac-msg', '', '');
    try {
      const data = await API.post('bac_get', {});
      this.render(data.snap);
    } catch (e) {
      UI.msg('bac-msg', 'error', e.message);
    }
  },

  async deal() {
    UI.msg('bac-msg', '', '');
    try {
      const data = await API.post('bac_deal', {});
      this.render(data.snap);
      UI.msg('bac-msg', 'ok', 'Hand dealt.');
    } catch (e) {
      UI.msg('bac-msg', 'error', e.message);
    }
  },

  async resetRound() {
    UI.msg('bac-msg', '', '');
    try {
      const data = await API.post('bac_reset', {});
      this.render(data.snap);
      UI.msg('bac-msg', 'ok', 'Round reset.');
    } catch (e) {
      UI.msg('bac-msg', 'error', e.message);
    }
  },

  render(snap) {
    document.getElementById('bac-player').innerHTML = renderCards(snap.player || []);
    document.getElementById('bac-banker').innerHTML = renderCards(snap.banker || []);
    document.getElementById('bac-player-total').textContent = snap.player && snap.player.length ? `Total: ${snap.playerTotal}` : 'Total: —';
    document.getElementById('bac-banker-total').textContent = snap.banker && snap.banker.length ? `Total: ${snap.bankerTotal}` : 'Total: —';

    const rEl = document.getElementById('bac-result');
    if (snap.result && snap.result.label) {
      const cls = snap.result.outcome === 'player' ? 'tier tier-3' :
                  snap.result.outcome === 'banker' ? 'tier tier-1' : 'tier tier-cons';
      rEl.className = cls;
      rEl.textContent = snap.result.label;
    } else {
      rEl.className = 'tier';
      rEl.textContent = '—';
    }

    this.renderHistory(snap.history || []);
  },

  renderHistory(items) {
    const el = document.getElementById('bac-history');
    if (!items.length) {
      el.innerHTML = `<div class="histitem">No baccarat hands yet. Click <b>Deal</b>.</div>`;
      return;
    }
    el.innerHTML = items.map(it => `
      <div class="histitem">
        <div class="t">${escapeHtml(it.label)} <span class="muted" style="font-weight:800;">• ${escapeHtml(it.time)}</span></div>
        <div class="mini">
          <span class="chip">Player: ${escapeHtml((it.player||[]).map(c => c.r + c.s).join(' '))} (Total ${it.playerTotal})</span>
        </div>
        <div class="mini">
          <span class="chip">Banker: ${escapeHtml((it.banker||[]).map(c => c.r + c.s).join(' '))} (Total ${it.bankerTotal})</span>
        </div>
        <div class="mini">
          <span class="chip">${it.natural ? 'Natural (8/9)' : 'Third-card rules applied'}</span>
        </div>
      </div>
    `).join('');
  }
};

// initial loads
Lotto.refresh();
BJ.refresh();
Bac.refresh();
</script>
</body>
</html>
