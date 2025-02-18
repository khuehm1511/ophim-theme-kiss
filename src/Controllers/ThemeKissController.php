<?php

namespace Ophim\ThemeKiss\Controllers;

use Backpack\Settings\app\Models\Setting;
use Illuminate\Http\Request;
use Ophim\Core\Models\Actor;
use Ophim\Core\Models\Catalog;
use Ophim\Core\Models\Category;
use Ophim\Core\Models\Director;
use Ophim\Core\Models\Episode;
use Ophim\Core\Models\Movie;
use Ophim\Core\Models\Region;
use Ophim\Core\Models\Tag;

use Illuminate\Support\Facades\Cache;

class ThemeKissController
{
    public function index(Request $request)
    {
        if ($request['search'] || $request['filter']) {
            $data = Movie::when(!empty($request['filter']['category']), function ($movie) {
                $movie->whereHas('categories', function ($categories) {
                    $categories->where('id', request('filter')['category']);
                });
            })->when(!empty($request['filter']['region']), function ($movie) {
                $movie->whereHas('regions', function ($regions) {
                    $regions->where('id', request('filter')['region']);
                });
            })->when(!empty($request['filter']['year']), function ($movie) {
                $movie->where('publish_year', request('filter')['year']);
            })->when(!empty($request['filter']['type']), function ($movie) {
                $movie->where('type', request('filter')['type']);
            })->when(!empty($request['search']), function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%' . request('search') . '%')
                        ->orWhere('origin_name', 'like', '%' . request('search')  . '%');
                });
            })->when(!empty($request['filter']['sort']), function ($movie) {
                if (request('filter')['sort'] == 'create') {
                    return $movie->orderBy('created_at', 'desc');
                }
                if (request('filter')['sort'] == 'update') {
                    return $movie->orderBy('updated_at', 'desc');
                }
                if (request('filter')['sort'] == 'year') {
                    return $movie->orderBy('publish_year', 'desc');
                }
                if (request('filter')['sort'] == 'view') {
                    return $movie->orderBy('view_total', 'desc');
                }
            })->paginate(35);

            return view('themes::themekiss.catalog', [
                'data' => $data,
                'search' => $request['search'],
                'section_name' => "Tìm kiếm phim: $request->search"
            ]);
        }

        $trending_this_week = Cache::get('trending_this_week');
        if(is_null($trending_this_week)) {
            $trending_this_week = Movie::orderBy('view_week', 'desc')->limit(1)->get()->first();
            Cache::put('trending_this_week', $trending_this_week, setting('site_cache_ttl', 5 * 60));
        }
        return view('themes::themekiss.index', [
            'title' => Setting::get('site_homepage_title'),
            'trending_this_week' => $trending_this_week
        ]);
    }

    public function getMovieOverview(Request $request, $movie)
    {
        /** @var Movie */
        $movie = Movie::fromCache()->find($movie);

        if (is_null($movie)) abort(404);

        $movie->generateSeoTags();

        $movie->increment('view_total', 1);
        $movie->increment('view_day', 1);
        $movie->increment('view_week', 1);
        $movie->increment('view_month', 1);

        $movie_related_cache_key = 'movie_related.' . $movie->id;
        $movie_related = Cache::get($movie_related_cache_key);
        if(is_null($movie_related)) {
            $movie_related = $movie->categories[0]->movies()->inRandomOrder()->limit(10)->get();
            Cache::put($movie_related_cache_key, $movie_related, setting('site_cache_ttl', 5 * 60));
        }

        return view('themes::themekiss.single', [
            'currentMovie' => $movie,
            'title' => $movie->getTitle(),
            'movie_related' => $movie_related
        ]);
    }

    public function getEpisode(Request $request, $movie, $slug)
    {
        $movie = Movie::fromCache()->find($movie)->load('episodes');

        if (is_null($movie)) abort(404);

        /** @var Episode */
        $episode = $movie->episodes->when(request('id'), function ($collection) {
            return $collection->where('id', request('id'));
        })->firstWhere('slug', $slug);

        if (is_null($episode)) abort(404);

        $episode->generateSeoTags();

        $movie->increment('view_total', 1);
        $movie->increment('view_day', 1);
        $movie->increment('view_week', 1);
        $movie->increment('view_month', 1);

        $movie_related_cache_key = 'movie_related.' . $movie->id;
        $movie_related = Cache::get($movie_related_cache_key);
        if(is_null($movie_related)) {
            $movie_related = $movie->categories[0]->movies()->inRandomOrder()->limit(10)->get();
            Cache::put($movie_related_cache_key, $movie_related, setting('site_cache_ttl', 5 * 60));
        }

        return view('themes::themekiss.episode', [
            'currentMovie' => $movie,
            'movie_related' => $movie_related,
            'episode' => $episode,
            'title' => $episode->getTitle()
        ]);
    }

    public function reportEpisode(Request $request, $movie, $slug)
    {
        $movie = Movie::fromCache()->find($movie)->load('episodes');

        $episode = $movie->episodes->when(request('id'), function ($collection) {
            return $collection->where('id', request('id'));
        })->firstWhere('slug', $slug);

        $episode->update([
            'report_message' => request('message', ''),
            'has_report' => true
        ]);

        return response([], 204);
    }

    public function rateMovie(Request $request, $movie)
    {

        $movie = Movie::fromCache()->find($movie);

        $movie->refresh()->increment('rating_count', 1, [
            'rating_star' => $movie->rating_star +  ((int) request('rating') - $movie->rating_star) / ($movie->rating_count + 1)
        ]);

        return response()->json(['status' => true, 'rating_star' => number_format($movie->rating_star, 1), 'rating_count' => $movie->rating_count]);
    }

    public function getMovieOfCategory(Request $request, $slug)
    {
        /** @var Category */
        $category = Category::fromCache()->find($slug);

        if (is_null($category)) abort(404);

        $category->generateSeoTags();

        $movies = $category->movies()->orderBy('created_at', 'desc')->paginate(35);

        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'category' => $category,
            'title' => $category->seo_title ?: $category->getTitle(),
            'section_name' => "Phim thể loại $category->name"
        ]);
    }

    public function getMovieOfRegion(Request $request, $slug)
    {
        /** @var Region */
        $region = Region::fromCache()->find($slug);

        if (is_null($region)) abort(404);

        $region->generateSeoTags();

        $movies = $region->movies()->orderBy('created_at', 'desc')->paginate(35);

        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'region' => $region,
            'title' => $region->seo_title ?: $region->getTitle(),
            'section_name' => "Phim quốc gia $region->name"
        ]);
    }

    public function getMovieOfActor(Request $request, $slug)
    {
        /** @var Actor */
        $actor = Actor::fromCache()->find($slug);

        if (is_null($actor)) abort(404);

        $actor->generateSeoTags();

        $movies = $actor->movies()->orderBy('created_at', 'desc')->paginate(35);

        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'person' => $actor,
            'title' => $actor->getTitle(),
            'section_name' => "Diễn viên $actor->name"
        ]);
    }

    public function getMovieOfDirector(Request $request, $slug)
    {
        /** @var Director */
        $director = Director::fromCache()->find($slug);

        if (is_null($director)) abort(404);

        $director->generateSeoTags();

        $movies = $director->movies()->orderBy('created_at', 'desc')->paginate(35);

        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'person' => $director,
            'title' => $director->getTitle(),
            'section_name' => "Đạo diễn $director->name"
        ]);
    }

    public function getMovieOfTag(Request $request, $slug)
    {
        /** @var Tag */
        $tag = Tag::fromCache()->find($slug);

        if (is_null($tag)) abort(404);

        $tag->generateSeoTags();

        $movies = $tag->movies()->orderBy('created_at', 'desc')->paginate(35);
        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'tag' => $tag,
            'title' => $tag->getTitle(),
            'section_name' => "Tags: $tag->name"
        ]);
    }

    public function getMovieOfType(Request $request, $slug)
    {
        /** @var Catalog */
        $catalog = Catalog::fromCache()->find($slug);

        if (is_null($catalog)) abort(404);

        $catalog->generateSeoTags();

        $cache_key = 'catalog.' . $catalog->id . '.page.' . $request['page'];
        $movies = Cache::get($cache_key);
        if(is_null($movies)) {
            $value = explode('|', trim($catalog->value));
            [$relation_config, $field, $val, $sortKey, $alg] = array_merge($value, ['', 'is_copyright', 0, 'created_at', 'desc']);
            $relation_config = explode(',', $relation_config);

            [$relation_table, $relation_field, $relation_val] = array_merge($relation_config, ['', '', '']);
            try {
                $movies = \Ophim\Core\Models\Movie::when($relation_table, function ($query) use ($relation_table, $relation_field, $relation_val, $field, $val) {
                    $query->whereHas($relation_table, function ($rel) use ($relation_field, $relation_val, $field, $val) {
                        $rel->where($relation_field, $relation_val)->where(array_combine(explode(",", $field), explode(",", $val)));
                    });
                })->when(!$relation_table, function ($query) use ($field, $val) {
                    $query->where(array_combine(explode(",", $field), explode(",", $val)));
                })
                ->orderBy($sortKey, $alg)
                ->paginate($catalog->paginate);

                Cache::put($cache_key, $movies, setting('site_cache_ttl', 5 * 60));
            } catch (\Exception $e) {}
        }

        return view('themes::themekiss.catalog', [
            'data' => $movies,
            'section_name' => "Danh sách $catalog->name"
        ]);
    }
}
