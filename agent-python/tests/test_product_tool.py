from oryntra_agent.agent.tools import (
    QueryProductsRequest,
    QueryProductsResponse,
    query_products,
)


def test_query_products_posts_to_laravel(monkeypatch, httpx_mock) -> None:
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.laravel_internal_base_url",
        "http://laravel-app",
    )
    monkeypatch.setattr(
        "oryntra_agent.agent.tools.settings.agent_runtime_internal_token",
        "ci-token",
    )
    httpx_mock.add_response(
        method="POST",
        url="http://laravel-app/api/internal/agent-tools/query-products",
        json={
            "products": [
                {
                    "id": 1,
                    "name": "Bike Eletrica Urbana",
                    "sku": "BIKE-001",
                    "description": "Autonomia de 50km.",
                    "price": 3499.9,
                    "category": "Bikes",
                }
            ],
            "total": 1,
        },
    )

    response = query_products(
        QueryProductsRequest(
            workspace_id=1,
            agent_id=10,
            agent_run_id=55,
            specialist_id=5,
            query="bike",
            category="Bikes",
            limit=10,
        )
    )

    request = httpx_mock.get_request()

    assert isinstance(response, QueryProductsResponse)
    assert response.total == 1
    assert response.products[0]["name"] == "Bike Eletrica Urbana"
    assert request is not None
    assert request.headers["X-Internal-Token"] == "ci-token"
    assert request.url.path == "/api/internal/agent-tools/query-products"
