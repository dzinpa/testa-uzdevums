<?php

class Vod {  //? class name

    public static function transform(&$movie) //? function name
    {
        $application = JFactory::getApplication();
        $input = $application->input;
		
		$objType = ItemType::oldSchemaTypeToNewMapping($movie->type);
        $fields = Api::getQueryParameters()->getFieldSets()[$objType] ?? null;
		
        $movie->actor = json_decode($movie->actor);
        $movie->director = json_decode($movie->director);
        $movie->languages = json_decode($movie->languages);
		$genresOriginal = json_decode($movie->imdb_genre);
       
		$regularTitle = '/\(HD\)[ ]*/';
		
		
		$genres = null;
		$movie->default_quality = null;
		$movie->languages = null;
		$userData = null;
		$movie->deflang = null;
	    $movie->sdeflang = null;
		
        if (strlen($movie->picture_large) == 0) {
            $movie->picture_large = $movie->picture_large_original ?? null;
        }
		
		// ? maybe there need function getQuality()
        if (self::shallIAddThis($fields, 'quality') && isset($movie->qualities) && json_decode($movie->qualities) != null) {
            $movie->quality = StreamQuality::getStreamQuality(json_decode($movie->qualities));
        }
        if (self::shallIAddThis($fields, 'default-quality') && isset($movie->qualities) && json_decode($movie->qualities) != null) {
            $defaultQuality = StreamQuality::getDefaultStreamQuality(json_decode($movie->qualities));
        }

        if (isset($defaultQuality) && count($defaultQuality) > 0) {
            $movie->default_quality = $defaultQuality[0];
        }
		
		$movie->title = preg_replace(regularTitle, '', $movie->title, 1);
        $movie->title_original = preg_replace(regularTitle, '', $movie->title_original, 1);

        //IMDB genre lookup for translation
		$db = queryTitles();
        $genreTranslation = $db->loadAssocList("title_original", "title");
        
        //warning fix "Invalid argument supplied for foreach()"
        if ($genresOriginal != null) {
            foreach ($genresOriginal as $genre) {
               $genres[] = $genreTranslation[$genre] ?? ''; 
            }
        }
		
        $movie->imdb_genre = $genres;
		
        if (self::shallIAddThis($fields, 'language') || self::shallIAddThis($fields, 'default-language')) {
            $movie->languages = ($movie->languages) ? StreamLanguage::getStreamLanguages($movie->languages) : [];
        } 
            
        if (self::shallIAddThis($fields, "default-language") || self::shallIAddThis($fields, "default-subtitle-language")) {
			 $userData = getUserData();
		}
		
		$streamLanguages = [];
		// ? maybe there need function checkLanguage()
        if (self::shallIAddThis($fields, "default-language")) {
			$ordering = 0;
            $needsBreak = false; 
           
            if ($userData != null && isset($userData->lang)) {
                $preferred = json_decode($userData->lang);
                foreach ($preferred as $pLang) {
                    foreach ($movie->languages as $key => $language) {
                        if (trim($pLang) == $language->id) {
                            $language->ordering = (string)$ordering;
                            $ordering++;
                            $streamLanguages[] = $language;
                            $needsBreak = true;
                            break;
                        }
                    }
                    if ($needsBreak) {
                        break;
                    }
                }
            }
        }
		
        //Stream language map
        if (isset($streamLanguages[0])) {
            $movie->deflang = $streamLanguages[0];
        } elseif (empty($streamLanguages) && isset($movie->languages[0])) {
            $movie->deflang = $movie->languages[0];
        } 
            
        if (self::shallIAddThis($fields, 'subtitles') || self::shallIAddThis($fields, 'default-subtitle-language')) {
            $movieSubtitles = json_decode($movie->subtitles);
            $subtitleObj = new Subtitle();
            if (count($movieSubtitles) > 0) {
                $movie->subtitles = $subtitleObj::getSubtitleLanguages($movieSubtitles);
                $movieSubtitles = array_merge($movieSubtitles, ['-']);
            } else {
                $movie->subtitles = null;
                $movieSubtitles = ['-'];
            }
            if (self::shallIAddThis($fields, 'default-subtitle-language')) {
                $movie->sdeflang = $subtitleObj::getDefaultSubtitle($movieSubtitles, $userData);         
            }
        }
		
        //Comma separated to array
        if (isset($movie->seasons) && $movie->seasons) {
            $movie->seasons = explode(",", $movie->seasons);
        } elseif (isset($movie->seasons) && $movie->seasons === "0") {
            $movie->seasons = [$movie->seasons];
        }

        if (self::shallIAddThis($fields, 'actual-episode')) {
            if (isset($movie->actual_episode) && $movie->actual_episode === $movie->movie_id) {
                $movie->actual_episode = $movie;
            } elseif (isset($movie->actual_episode)) {
                $movie->actual_episode = Vod::getVod($movie->actual_episode, (isset($movie->recurrsion) ? $movie->recurrsion : null))[0];
            }
        }

        if(self::shallIAddThis($fields, 'is_paid')&& $movie->is_premium ){
            $tokendata = json_decode($input->getString('tokendata'));
            $userId = $tokendata->user_id ?? null;
            if ($userId) {
                try{
                    $movie->is_paid = ((VodStream::hasUserBoughtMovie($userId, $movie->movie_id))?"1":"0");
                }catch (\Exception $e){
                    $movie->is_paid = '0';
                }
            }
        }
    }
	
	function queryTitles() {
		$db = JFactory::getDbo();
		$lang = JFactory::getLanguage();
		$query = $db->getQuery(true);
		$query
			->select($db->quoteName('g.title_original'))
			->select("coalesce(NULLIF(g.title, ''), g.title_original) as  title")
			->from($db->quoteName('#__vod_genre', 'g'))
			->where($db->quoteName('g.lang') . '=' . $db->quote($lang->getTag()))
			->where($db->quoteName('g.state') . ' = 1 ');
		$db->setQuery($query);
				
		return $db;
	}
			
		function getUserData() {
		$tokendata = json_decode($input->getString('tokendata'));
        $userId = $tokendata->user_id ?? null;
        if ($userId) {
			$profile = $tokendata->profile??(Profile::getFirstProfileId($userId));
			if($profile){
				$query = $db->getQuery(true);
				if ($userId != null) {
					$query
						->select('*')
						->from('#__user_settings')
						->where($db->quoteName('profile_id') . ' = ' . $db->quote($profile));
					$db->setQuery($query);
						
					return $db->loadObject();
				}
			}
        }
    }
}