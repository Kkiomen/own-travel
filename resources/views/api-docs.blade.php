<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="noindex, nofollow, noarchive" />
        <title>{{ config('app.name') }} - deals API</title>

        {{-- Swagger UI is loaded from a CDN rather than bundled: it is a
             developer's page on a private app, not part of the dashboard's
             asset build, and pulling it through Vite would put a megabyte of
             vendor JavaScript into every deploy. Offline, the JSON document at
             {{ $specificationUrl }} is still the contract. --}}
        <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
        <style>
            body {
                margin: 0;
                background: #fafafa;
            }
        </style>
    </head>
    <body>
        <div id="swagger-ui"></div>

        <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
        <script>
            window.addEventListener('load', () => {
                window.SwaggerUIBundle({
                    url: @json($specificationUrl),
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    displayRequestDuration: true,
                    tryItOutEnabled: true,
                });
            });
        </script>
    </body>
</html>
