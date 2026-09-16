<?php

namespace Workbench\App\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;

#[Provider('openai')]
#[Model('gpt-5.6-luna')]
class ExpenditureDetailsRelatedNotesAnalyzer extends WeatherAgent {}
