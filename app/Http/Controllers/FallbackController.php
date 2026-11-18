<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Unmatched URLs land here instead of failing before the web middleware, so the
 * error page knows the tenant and the signed-in user (ErrorPageArea).
 *
 * A fallback route is matched before Laravel looks for the path under another
 * method, so that check is repeated here: a known path called with the wrong
 * method stays 405 (and OPTIONS keeps its Allow answer) instead of becoming 404.
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request, Router $router): Response
    {
        $allowed = [];

        foreach (array_diff(Router::$verbs, [$request->getMethod()]) as $method) {
            foreach ($router->getRoutes()->get($method) as $route) {
                if (! $route->isFallback && $route->matches($request, false)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if ($allowed === []) {
            abort(404);
        }

        if ($request->isMethod('OPTIONS')) {
            return new Response('', 200, ['Allow' => implode(',', $allowed)]);
        }

        throw new MethodNotAllowedHttpException($allowed, sprintf(
            'The %s method is not supported for route %s. Supported methods: %s.',
            $request->getMethod(),
            $request->path(),
            implode(', ', $allowed)
        ));
    }
}
