<?php

namespace Mpdf\Http;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 URI implementation ported from nyholm/psr7 and adapted for PHP 5.6
 *
 * @link https://github.com/Nyholm/psr7/blob/master/src/Uri.php
 */
class Response implements ResponseInterface
{

  /** @var array Map of standard HTTP status code/reason phrases */
	private static array $phrases = [
	100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing',
	200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-status', 208 => 'Already Reported',
	300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 306 => 'Switch Proxy', 307 => 'Temporary Redirect',
	400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Time-out', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 416 => 'Requested range not satisfiable', 417 => 'Expectation Failed', 418 => 'I\'m a teapot', 422 => 'Unprocessable Entity', 423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Unordered Collection', 426 => 'Upgrade Required', 428 => 'Precondition Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
	500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Time-out', 505 => 'HTTP Version not supported', 506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected', 511 => 'Network Authentication Required',
	];

  /** @var string */
	private mixed $reasonPhrase;

  /** @var int */
	private int $statusCode;

  /** @var array Map of all registered headers, as original name => array of values */
	private array $headers = [];

  /** @var array Map of lowercase header name => original name at registration */
	private array $headerNames = [];

  /** @var string */
	private string $protocol;

	private Stream|StreamInterface $stream;

  /**
   * @param int $status Status code
   * @param array $headers Response headers
   * @param string|StreamInterface|null $body Response body
   * @param string $version Protocol version
   * @param string|null $reason Reason phrase (when empty a default will be used based on the status code)
   */
	public function __construct(int $status = 200, array $headers = [], StreamInterface|string $body = null, string $version = '1.1', string $reason = null)
	{
	  // If we got no body, defer initialization of the stream until Response::getBody()
		if ('' !== $body && null !== $body) {
			$this->stream = Stream::create($body);
		}

		$this->statusCode = $status;
		$this->setHeaders($headers);
		if (null === $reason && isset(self::$phrases[$this->statusCode])) {
			$this->reasonPhrase = self::$phrases[$status];
		} else {
			$this->reasonPhrase = $reason ?? '';
		}

		$this->protocol = $version;
	}

	public function getStatusCode(): int
	{
		return $this->statusCode;
	}

	public function getReasonPhrase(): string
	{
		return $this->reasonPhrase;
	}

	public function withStatus($code, $reasonPhrase = ''): ResponseInterface
	{
		if (!\is_int($code) && !\is_string($code)) {
			throw new InvalidArgumentException('Status code has to be an integer');
		}

		$code = (int) $code;
		if ($code < 100 || $code > 599) {
			throw new InvalidArgumentException(\sprintf('Status code has to be an integer between 100 and 599. A status code of %d was given', $code));
		}

		$new = clone $this;
		$new->statusCode = $code;
		if ((null === $reasonPhrase || '' === $reasonPhrase) && isset(self::$phrases[$new->statusCode])) {
			$reasonPhrase = self::$phrases[$new->statusCode];
		}
		$new->reasonPhrase = $reasonPhrase;

		return $new;
	}

	public function getProtocolVersion(): string
	{
		return $this->protocol;
	}

	public function withProtocolVersion($version): MessageInterface
	{
		if ($this->protocol === $version) {
			return $this;
		}

		$new = clone $this;
		$new->protocol = $version;

		return $new;
	}

	public function getHeaders(): array
	{
		return $this->headers;
	}

	public function hasHeader($name): bool
	{
		return isset($this->headerNames[strtolower($name)]);
	}

	public function getHeader($name): array
	{
		$name = strtolower($name);

		if (!isset($this->headerNames[$name])) {
			return [];
		}

		$name = $this->headerNames[$name];

		return $this->headers[$name];
	}

	public function getHeaderLine($name): string
	{
		return implode(', ', $this->getHeader($name));
	}

	public function withHeader($name, $value): MessageInterface
	{
		if (!is_array($value)) {
			$value = [$value];
		}

		$value = $this->trimHeaderValues($value);
		$normalized = strtolower($name);

		$new = clone $this;
		if (isset($new->headerNames[$normalized])) {
			unset($new->headers[$new->headerNames[$normalized]]);
		}
		$new->headerNames[$normalized] = $name;
		$new->headers[$name] = $value;

		return $new;
	}

	public function withAddedHeader($name, $value): MessageInterface
	{
		if (!is_array($value)) {
			$value = [$value];
		}

		$value = $this->trimHeaderValues($value);
		$normalized = strtolower($name);

		$new = clone $this;
		if (isset($new->headerNames[$normalized])) {
			$name = $this->headerNames[$normalized];
			$new->headers[$name] = array_merge($this->headers[$name], $value);
		} else {
			$new->headerNames[$normalized] = $name;
			$new->headers[$name] = $value;
		}

		return $new;
	}

	public function withoutHeader($name): MessageInterface
	{
		$normalized = strtolower($name);

		if (!isset($this->headerNames[$normalized])) {
			return $this;
		}

		$name = $this->headerNames[$normalized];

		$new = clone $this;
		unset($new->headers[$name], $new->headerNames[$normalized]);

		return $new;
	}

	public function getBody(): StreamInterface
	{
		if (!$this->stream) {
			$this->stream = Stream::create('');
		}

		return $this->stream;
	}

	public function withBody(StreamInterface $body): MessageInterface
	{
		if ($body === $this->stream) {
			return $this;
		}

		$new = clone $this;
		$new->stream = $body;

		return $new;
	}

	private function setHeaders(array $headers): void
	{
		$this->headerNames = $this->headers = [];
		foreach ($headers as $header => $value) {
			if (!is_array($value)) {
				$value = [$value];
			}

			$value = $this->trimHeaderValues($value);
			$normalized = strtolower($header);
			if (isset($this->headerNames[$normalized])) {
				$header = $this->headerNames[$normalized];
				$this->headers[$header] = array_merge($this->headers[$header], $value);
			} else {
				$this->headerNames[$normalized] = $header;
				$this->headers[$header] = $value;
			}
		}
	}

  /**
   * Trims whitespace from the header values.
   *
   * Spaces and tabs ought to be excluded by parsers when extracting the field value from a header field.
   *
   * header-field = field-name ":" OWS field-value OWS
   * OWS          = *( SP / HTAB )
   *
   * @param string[] $values Header values
   *
   * @return string[] Trimmed header values
   *
   * @see https://tools.ietf.org/html/rfc7230#section-3.2.4
   */
	private function trimHeaderValues(array $values): array
	{
		return array_map(static function ($value) {
			return trim($value, " \t");
		}, $values);
	}

}
