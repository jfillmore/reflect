# ---  DO NOT EXPOSE EXTERNALLY! ---

# Reflect

Debugging aids for causing all sorts of HTTP response conditions.


## Request Headers:

The caller can customize the response we get back to test specific behaviors:

- `X-Refl-Status: str` = Set a custom response status code.
- `X-Refl-Session: str` = Set session to a specific key; default="default"
- `X-Refl-Delay: float` = Extra delay on response in number of seconds
- `X-Refl-Padding: unit` = Pad the response with extra data (e.g. 1, 1B, 3K, 10M)
- `X-Refl-Stream: unit` = Stream the response in chunks of the given size
- `X-Refl-Stream-Delay: float` = Delay between stream chunks in seconds
- `X-Refl-Body: 0|1` = Copy the response body/content-type from the request
- `X-Refl-Proxy: str` = Proxy back response from another URL

Units are specified either in number of bytes or as a string ending in a suffix
of "B", "K', or "M" bytes, kilobytes, and megabytes.


## Response Data

Returns a JSON response using "text/plain" unless JSON is requested via the
"Accept" header.

Returns request and session description information as well as extra "meta"
data based on any runtime behaviors invoked. Certain headers can override the
response body and headers to return arbitrary data.


## Examples:

> Return request diagnostics
```
# text/plain w/ formatted JSON
$ curl localhost:8123

# formatted JSON w/ proper content-type header
$ curl localhost:8123 -H 'Accept: application/json'

# misc extras
$ curl localhost:8123/foo?bar=3 \
    -d '{"foo":"bar"}' \
    -H 'X-Random-Header: foobar' \
    -H 'Content-type: application/json'
```

> Proxy a streamed response from an external URL
```
$ curl localhost:8123 \
    -H 'x-refl-stream: 1024' \
    -H 'x-refl-stream-delay: 0.1' \
    -H 'x-refl-proxy: https://duckduckgo.com'
```

... and more!
