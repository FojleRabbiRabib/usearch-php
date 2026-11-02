/**
 * tests/quality/interop_cpp.cpp — C++ reference helper for cross-language
 * index-file interoperability tests against usearch-php.
 *
 * Copyright 2026 Fojle Rabbi (Rabib)
 * SPDX-License-Identifier: Apache-2.0
 */

#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <sstream>
#include <string>
#include <vector>

#include <usearch/index_dense.hpp>

using namespace unum::usearch;
using u_key_t = std::uint64_t;

struct TestCaseConfig {
	std::string name;
	metric_kind_t metric;
	scalar_kind_t scalar;
	std::size_t dimensions;
	std::size_t count;
	std::size_t queries;
	bool has_removals;
};

static TestCaseConfig const kConfigs[] = {
	{"cos_f32_128", metric_kind_t::cos_k, scalar_kind_t::f32_k, 128, 500, 20, false},
	{"l2_f32_64", metric_kind_t::l2sq_k, scalar_kind_t::f32_k, 64, 500, 20, false},
	{"ip_f32_256", metric_kind_t::ip_k, scalar_kind_t::f32_k, 256, 500, 20, false},
	{"cos_f16_128", metric_kind_t::cos_k, scalar_kind_t::f16_k, 128, 500, 20, false},
	{"cos_i8_128", metric_kind_t::cos_k, scalar_kind_t::i8_k, 128, 500, 20, false},
	{"hamming_b1_256", metric_kind_t::hamming_k, scalar_kind_t::b1x8_k, 256, 500, 20, false},
	{"haversine_f32_2", metric_kind_t::haversine_k, scalar_kind_t::f32_k, 2, 200, 10, false},
	{"lifecycle_tomb", metric_kind_t::cos_k, scalar_kind_t::f32_k, 64, 300, 20, true},
	{"empty_f32_128", metric_kind_t::cos_k, scalar_kind_t::f32_k, 128, 0, 5, false},
};

static TestCaseConfig const *findConfig(std::string const &name)
{
	for (auto const &cfg : kConfigs) {
		if (cfg.name == name) {
			return &cfg;
		}
	}
	return nullptr;
}

static void generateVector(std::uint32_t &state, std::vector<float> &vec, std::size_t dim, metric_kind_t m_kind,
						   scalar_kind_t s_kind)
{
	vec.resize(dim);
	for (std::size_t d = 0; d < dim; ++d) {
		state = state * 1664525u + 1013904223u;
		float v = static_cast<float>(state % 10000u) / 10000.0f;
		if (m_kind == metric_kind_t::haversine_k) {
			if (d == 0) {
				vec[d] = (v - 0.5f) * 3.141592653589793f;
			} else {
				vec[d] = (v - 0.5f) * 6.283185307179586f;
			}
		} else if (s_kind == scalar_kind_t::b1x8_k || m_kind == metric_kind_t::hamming_k) {
			vec[d] = (v > 0.5f) ? 1.0f : -1.0f;
		} else {
			vec[d] = v;
		}
	}
}

static double allowedTolerance(scalar_kind_t s_kind, metric_kind_t m_kind)
{
	if (s_kind == scalar_kind_t::b1x8_k || m_kind == metric_kind_t::hamming_k) {
		return 0.0;
	}
	if (s_kind == scalar_kind_t::i8_k) {
		return 0.02;
	}
	if (s_kind == scalar_kind_t::f16_k) {
		return 0.005;
	}
	return 0.0002;
}

static int actionGenerate(TestCaseConfig const &cfg, std::string const &index_path, std::string const &manifest_path)
{
	metric_punned_t metric(cfg.dimensions, cfg.metric, cfg.scalar);
	index_dense_config_t idx_cfg;
	idx_cfg.connectivity = 16;
	idx_cfg.expansion_add = 128;
	idx_cfg.expansion_search = 64;

	index_dense_t index = index_dense_t::make(metric, idx_cfg);
	if (cfg.count > 0) {
		index.reserve(cfg.count);
	}

	std::uint32_t state = 424242u;
	std::vector<float> vec;
	for (std::size_t i = 0; i < cfg.count; ++i) {
		generateVector(state, vec, cfg.dimensions, cfg.metric, cfg.scalar);
		index.add(static_cast<u_key_t>(i + 1), vec.data());
	}

	if (cfg.has_removals) {
		for (std::size_t i = 0; i < cfg.count; ++i) {
			if ((i + 1) % 5 == 0) {
				index.remove(static_cast<u_key_t>(i + 1));
			}
		}
	}

	auto save_res = index.save(index_path.c_str());
	if (!save_res) {
		std::cerr << "FAIL: save failed: " << save_res.error.what() << std::endl;
		return 1;
	}

	std::ofstream manifest(manifest_path);
	if (!manifest.is_open()) {
		std::cerr << "FAIL: cannot write manifest: " << manifest_path << std::endl;
		return 1;
	}

	manifest << "case_id=" << cfg.name << "\n";
	manifest << "count=" << index.size() << " dimensions=" << cfg.dimensions << "\n";

	std::uint32_t q_state = 987654u;
	std::size_t const top_k = 10;
	for (std::size_t q = 0; q < cfg.queries; ++q) {
		generateVector(q_state, vec, cfg.dimensions, cfg.metric, cfg.scalar);
		auto matches = index.search(vec.data(), top_k);

		manifest << "query_idx " << q << " count " << matches.size() << " keys ";
		for (std::size_t m = 0; m < matches.size(); ++m) {
			if (m > 0) {
				manifest << ",";
			}
			manifest << matches[m].member.key;
		}
		manifest << " distances ";
		for (std::size_t m = 0; m < matches.size(); ++m) {
			if (m > 0) {
				manifest << ",";
			}
			manifest << std::setprecision(8) << matches[m].distance;
		}
		manifest << "\n";
	}

	manifest.close();
	return 0;
}

static int actionValidate(TestCaseConfig const &cfg, std::string const &index_path, std::string const &manifest_path)
{
	index_dense_t index_load;
	auto load_res = index_load.load(index_path.c_str());
	if (!load_res) {
		std::cerr << "FAIL: load failed: " << load_res.error.what() << std::endl;
		return 1;
	}

	index_dense_t index_view;
	auto view_res = index_view.view(index_path.c_str());
	if (!view_res) {
		std::cerr << "FAIL: view failed: " << view_res.error.what() << std::endl;
		return 1;
	}

	if (index_load.dimensions() != cfg.dimensions) {
		std::cerr << "FAIL: loaded dimensions " << index_load.dimensions() << " != expected " << cfg.dimensions
				  << std::endl;
		return 1;
	}
	if (index_view.dimensions() != cfg.dimensions) {
		std::cerr << "FAIL: viewed dimensions " << index_view.dimensions() << " != expected " << cfg.dimensions
				  << std::endl;
		return 1;
	}

	std::ifstream manifest(manifest_path);
	if (!manifest.is_open()) {
		std::cerr << "FAIL: cannot read manifest: " << manifest_path << std::endl;
		return 1;
	}

	std::string line;
	std::string line1, line2;
	if (!std::getline(manifest, line1) || !std::getline(manifest, line2)) {
		std::cerr << "FAIL: manifest header incomplete" << std::endl;
		return 1;
	}

	std::uint32_t q_state = 987654u;
	std::vector<float> vec;
	double tol = allowedTolerance(cfg.scalar, cfg.metric);

	while (std::getline(manifest, line)) {
		if (line.empty() || line.rfind("query_idx", 0) != 0) {
			continue;
		}
		std::istringstream iss(line);
		std::string tag, qidx_str, count_tag, k_str, keys_tag, keys_str, dists_tag, dists_str;
		iss >> tag >> qidx_str >> count_tag >> k_str >> keys_tag >> keys_str >> dists_tag >> dists_str;

		std::size_t exp_count = std::stoul(k_str);
		std::vector<u_key_t> exp_keys;
		std::vector<double> exp_dists;

		if (exp_count > 0) {
			std::stringstream ss_k(keys_str);
			std::string item;
			while (std::getline(ss_k, item, ',')) {
				if (!item.empty()) {
					exp_keys.push_back(static_cast<u_key_t>(std::stoull(item)));
				}
			}
			std::stringstream ss_d(dists_str);
			while (std::getline(ss_d, item, ',')) {
				if (!item.empty()) {
					exp_dists.push_back(std::stod(item));
				}
			}
			if (exp_keys.size() != exp_count || exp_dists.size() != exp_count) {
				std::cerr << "FAIL: manifest parse error for query " << qidx_str << ": expected "
						  << exp_count << " entries, got " << exp_keys.size() << " keys and "
						  << exp_dists.size() << " distances" << std::endl;
				return 1;
			}
		}

		generateVector(q_state, vec, cfg.dimensions, cfg.metric, cfg.scalar);
		auto load_matches = index_load.search(vec.data(), 10);
		auto view_matches = index_view.search(vec.data(), 10);

		if (load_matches.size() != exp_count || view_matches.size() != exp_count) {
			std::cerr << "FAIL: match count mismatch: exp=" << exp_count << " load=" << load_matches.size()
					  << " view=" << view_matches.size() << std::endl;
			return 1;
		}

		for (std::size_t m = 0; m < exp_count; ++m) {
			if (load_matches[m].member.key != exp_keys[m]) {
				std::cerr << "FAIL: key mismatch at " << m << ": load=" << load_matches[m].member.key
						  << " exp=" << exp_keys[m] << std::endl;
				return 1;
			}
			if (view_matches[m].member.key != exp_keys[m]) {
				std::cerr << "FAIL: key mismatch at " << m << ": view=" << view_matches[m].member.key
						  << " exp=" << exp_keys[m] << std::endl;
				return 1;
			}
			double d_diff_load = std::abs(static_cast<double>(load_matches[m].distance) - exp_dists[m]);
			double d_diff_view = std::abs(static_cast<double>(view_matches[m].distance) - exp_dists[m]);
			if (d_diff_load > tol || d_diff_view > tol) {
				std::cerr << "FAIL: distance mismatch at " << m << ": exp=" << exp_dists[m]
						  << " load=" << load_matches[m].distance << " view=" << view_matches[m].distance
						  << " tol=" << tol << std::endl;
				return 1;
			}
		}
	}

	if (cfg.count > 0 && !cfg.has_removals) {
		std::vector<float> retrieved(cfg.dimensions);
		std::size_t got = index_load.get(1, retrieved.data());
		if (got != 1) {
			std::cerr << "FAIL: key 1 retrieval count " << got << " != 1" << std::endl;
			return 1;
		}
		std::uint32_t state = 424242u;
		std::vector<float> exp_vec;
		generateVector(state, exp_vec, cfg.dimensions, cfg.metric, cfg.scalar);
		float norm = 0.0f;
		if (cfg.scalar == scalar_kind_t::i8_k) {
			for (float v : exp_vec) {
				norm += v * v;
			}
			norm = std::sqrt(norm);
		}
		for (std::size_t d = 0; d < cfg.dimensions; ++d) {
			float exp_val = exp_vec[d];
			if (cfg.scalar == scalar_kind_t::b1x8_k || cfg.metric == metric_kind_t::hamming_k) {
				exp_val = exp_val > 0.0f ? 1.0f : 0.0f;
			} else if (cfg.scalar == scalar_kind_t::i8_k) {
				exp_val = norm > 0.0f ? std::round(exp_vec[d] * 127.0f / norm) / 127.0f : 0.0f;
			}
			if (std::abs(retrieved[d] - exp_val) > tol + 0.02) {
				std::cerr << "FAIL: key 1 coord " << d << ": got " << retrieved[d] << " exp " << exp_val << std::endl;
				return 1;
			}
		}
	}

	return 0;
}

int main(int argc, char **argv)
{
	std::string action;
	std::string case_name;
	std::string index_path;
	std::string manifest_path;

	for (int i = 1; i < argc; ++i) {
		std::string arg = argv[i];
		if (arg.rfind("--action=", 0) == 0) {
			action = arg.substr(9);
		} else if (arg.rfind("--case=", 0) == 0) {
			case_name = arg.substr(7);
		} else if (arg.rfind("--index-file=", 0) == 0) {
			index_path = arg.substr(13);
		} else if (arg.rfind("--manifest-file=", 0) == 0) {
			manifest_path = arg.substr(16);
		}
	}

	if (action.empty() || case_name.empty() || index_path.empty() || manifest_path.empty()) {
		std::cerr << "Usage: interop_cpp --action=generate|validate --case=<id> --index-file=<path> "
					 "--manifest-file=<path>\n";
		return 2;
	}

	auto const *cfg = findConfig(case_name);
	if (!cfg) {
		std::cerr << "FAIL: unknown case " << case_name << std::endl;
		return 2;
	}

	if (action == "generate") {
		return actionGenerate(*cfg, index_path, manifest_path);
	} else if (action == "validate") {
		return actionValidate(*cfg, index_path, manifest_path);
	}

	std::cerr << "FAIL: unknown action " << action << std::endl;
	return 2;
}
