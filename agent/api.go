package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"
)

var (
	errUnauthorized = errors.New("não autorizado")
	errUnreachable  = errors.New("servidor inacessível")
)

type apiClient struct {
	baseURL string
	token   string
	http    *http.Client
}

type pairResult struct {
	Token string `json:"token"`
	Store string `json:"store"`
}

type printJobData struct {
	ID      int64  `json:"id"`
	Printer string `json:"printer"`
	Payload string `json:"payload"`
}

func newAPIClient(baseURL, token string) *apiClient {
	return &apiClient{
		baseURL: baseURL,
		token:   token,
		http:    &http.Client{Timeout: 20 * time.Second},
	}
}

func (c *apiClient) pair(code, name, platform, version string) (*pairResult, error) {
	var result pairResult

	err := c.do(http.MethodPost, "/api/print-agent/pair", map[string]string{
		"code":     code,
		"name":     name,
		"platform": platform,
		"version":  version,
	}, &result)

	return &result, err
}

func (c *apiClient) heartbeat(printers []string, version string) error {
	if printers == nil {
		printers = []string{}
	}

	return c.do(http.MethodPost, "/api/print-agent/heartbeat", map[string]any{
		"printers": printers,
		"version":  version,
	}, nil)
}

func (c *apiClient) jobs() ([]printJobData, error) {
	var body struct {
		Jobs []printJobData `json:"jobs"`
	}

	err := c.do(http.MethodGet, "/api/print-agent/jobs", nil, &body)

	return body.Jobs, err
}

func (c *apiClient) result(id int64, printed bool, failure string) error {
	status := "printed"
	if !printed {
		status = "failed"
	}

	if len(failure) > 240 {
		failure = failure[:240]
	}

	return c.do(http.MethodPost, fmt.Sprintf("/api/print-agent/jobs/%d/result", id), map[string]string{
		"status": status,
		"error":  failure,
	}, nil)
}

func (c *apiClient) do(method, path string, payload any, out any) error {
	var body io.Reader

	if payload != nil {
		encoded, err := json.Marshal(payload)
		if err != nil {
			return err
		}

		body = bytes.NewReader(encoded)
	}

	request, err := http.NewRequest(method, c.baseURL+path, body)
	if err != nil {
		return fmt.Errorf("%w: endereço inválido (%v)", errUnreachable, err)
	}

	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("User-Agent", "MenuExpressImpressao/"+version)

	if c.token != "" {
		request.Header.Set("Authorization", "Bearer "+c.token)
	}

	response, err := c.http.Do(request)
	if err != nil {
		return fmt.Errorf("%w: %v", errUnreachable, err)
	}
	defer response.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(response.Body, 5<<20))

	if response.StatusCode == http.StatusUnauthorized {
		return errUnauthorized
	}

	if response.StatusCode >= 400 {
		var problem struct {
			Message string `json:"message"`
		}

		if json.Unmarshal(raw, &problem) == nil && problem.Message != "" {
			return errors.New(problem.Message)
		}

		return fmt.Errorf("o servidor respondeu HTTP %d", response.StatusCode)
	}

	if out != nil {
		if err := json.Unmarshal(raw, out); err != nil {
			return fmt.Errorf("resposta inesperada do servidor: %v", err)
		}
	}

	return nil
}
